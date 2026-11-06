<?php

/**
 * Trash Guides Provider.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Arr;

use ErrorException;
use Phlix\Shared\Version;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Fetches and parses TRaSH-Guides quality profiles and custom formats JSON.
 *
 * REAL NETWORK CODE, OUTSIDE THE TRANSPORT SEAM: `fetchUrl()` performs its own
 * blocking stream fetch (30 s timeout per hop) — it does NOT go through
 * {@see \Phlix\Shared\Arr\Transport\ArrTransportInterface}, so there is nothing
 * to inject; in resident workers (phlix-server constructs this class directly
 * and `CustomFormatSyncer` consumes it) the fetch blocks the worker. Routing
 * these fetches through an injectable transport is the honest fix but is a
 * coordinated consumer-side cascade, deferred as an owner/tag-wave item.
 * LW-061 hardened what remains inside the class (2026-11-06): wrapper
 * auto-follow is OFF — every redirect target is resolved from the response
 * headers and re-validated against the https gate before the next hop is
 * dialed (hop budget 5, the stream setting it replaces), and fetch failures
 * are no longer `@`-silenced — the wrapper warning rides the exception chain
 * while public messages keep their stable, leak-free form (M-SHARED-22).
 *
 * KNOWN LIMITATIONS (documented behavior, each verified against the code):
 * - The three static caches (`$qualityProfilesCache`, `$customFormatsCache`,
 *   `$versionCache`) share ONE `$cacheTimestamp` that every populator resets,
 *   so refreshing item B extends item A's apparent age — worst-case staleness
 *   is ~2x the advertised 24 h TTL, and any item fetched while a sibling
 *   refreshes never expires on its own schedule.
 * - The same shared timestamp reaches the per-instance memos: every getter
 *   runs the TTL check BEFORE the memo hit (review shared__srphp Major #1,
 *   cured 2026-11-06 — a lone instance now refetches once its 24 h is up),
 *   but a sibling instance's refresh rewrites the shared timestamp, so an
 *   idle instance's memo can outlive 24 h of wall time while other instances
 *   keep the generation fresh. A memo is released when its own instance
 *   observes the shared timestamp past TTL; `clearCache()` on ANOTHER
 *   instance flushes the statics but cannot instantly dislodge this
 *   instance's memo (it dislodges on the next genuinely-expired check).
 * - The caches are `static` (process-wide): `clearCache()` on one instance
 *   flushes every other instance's cached view; per-instance isolation is
 *   NOT provided.
 * - `loadConfig()` looks for `config/trash_guides.php` relative to THIS
 *   package's own root — i.e. `vendor/detain/phlix-shared/config/` in consumer
 *   context, which ships no `config/` directory. The override is therefore
 *   unreachable for consumers: the hardcoded fallback URLs are effectively
 *   constants unless the vendor tree itself is edited. An included file that
 *   does not return an array is now rejected at load time, naming the path
 *   and the actual type (LW-061) — previously its `@var` promise was a
 *   comment, and a bare `return;` flowed onward as a phantom shape.
 * - The fallback URLs are INERT BY UPSTREAM REALITY, not merely mutable
 *   (forensics 2026-11-06, LW-061): the consolidated files they name do not
 *   exist in `TRaSH-Guides/Guides` at any probed ref — not on `master`
 *   (current HEAD or mid-2025 trees; `docs/json/radarr/` ships per-file
 *   subtrees `cf/`, `cf-groups/`, `quality-profiles/`, … only), not on
 *   `main` (the branch itself does not exist; the repo's default branch has
 *   always been `master`), and path-filtered commit history shows zero
 *   commits touching either consolidated filename. The org hosts only two
 *   repos. Default config therefore fails closed: 404 → fetch throws.
 *   Re-pointing at the per-file tree is a payload redesign that reaches the
 *   server-side `CustomFormatSyncer` contract — a cross-repo lane, not a
 *   fix this class can absorb alone.
 * - `getVersion()` fetches and fully decodes the entire custom-formats JSON
 *   to read one field, without populating `$customFormatsCache` — a duplicate
 *   eager decode of a ~100k-entry document per cold version read in a
 *   resident process.
 *
 * ## Subclassing and the path to `final`
 *
 * Not `final` because `phlix-server`'s Arr test seam extends it to stub the
 * fetch layer (`tests/Unit/Server/Arr/CustomFormatSyncerTest`'s
 * `MockableTrashGuidesProvider`). Outside that double treat this class as
 * effectively final; flipping `final` is a BREAKING change to coordinate
 * with phlix-server at a release seam.
 *
 * @package Phlix\Shared\Arr
 * @since 0.4.0
 */
class TrashGuidesProvider
{
    private const int CACHE_TTL_SECONDS = 86400; // 24 hours

    /**
     * Redirect hops one fetch may walk (LW-061). Mirrors the `max_redirects`
     * stream setting the manual redirect loop replaced; the budget, not the
     * wrapper, is enforced so every intermediate target passes the https gate.
     */
    private const int MAX_REDIRECT_HOPS = 5;

    /** @var array<string, mixed>|null Cached quality profiles data */
    private static ?array $qualityProfilesCache = null;

    /** @var array<string, mixed>|null Cached custom formats data */
    private static ?array $customFormatsCache = null;

    /** @var string|null Cached version string */
    private static ?string $versionCache = null;

    /** @var int|null Monotonic timestamp when cache was last set (in seconds) */
    private static ?int $cacheTimestamp = null;

    /** @var array<string, mixed>|null Parsed quality profiles */
    private ?array $qualityProfiles = null;

    /** @var array<string, mixed>|null Parsed custom formats */
    private ?array $customFormats = null;

    /** @var string|null Parsed version SHA */
    private ?string $version = null;

    private ?LoggerInterface $logger;

    /**
     * Creates a new TrashGuidesProvider instance.
     *
     * @param LoggerInterface|null $logger Optional logger instance.
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Fetches and returns quality profiles from TRaSH-Guides.
     *
     * @return array<string, mixed> Quality profiles data.
     * @throws RuntimeException On network or parsing errors.
     */
    public function getQualityProfiles(): array
    {
        $this->ensureCacheValid();

        if ($this->qualityProfiles !== null) {
            return $this->qualityProfiles;
        }

        if (self::$qualityProfilesCache !== null) {
            $this->qualityProfiles = self::$qualityProfilesCache;
            return $this->qualityProfiles;
        }

        $config = $this->loadConfig();
        $url = $config['quality_profiles_url'] ?? '';

        if (!is_string($url) || $url === '') {
            throw new RuntimeException('TRaSH-Guides quality profiles URL not configured');
        }

        $this->logger?->info('Fetching TRaSH-Guides quality profiles', ['url' => $url]);

        $json = $this->fetchUrl($url);
        $data = $this->parseJson($json);

        self::$qualityProfilesCache = $data;
        self::$cacheTimestamp = (int) (hrtime(true) / 1_000_000_000);

        $this->qualityProfiles = $data;
        return $this->qualityProfiles;
    }

    /**
     * Fetches and returns custom formats from TRaSH-Guides.
     *
     * @return array<string, mixed> Custom formats data.
     * @throws RuntimeException On network or parsing errors.
     */
    public function getCustomFormats(): array
    {
        $this->ensureCacheValid();

        if ($this->customFormats !== null) {
            return $this->customFormats;
        }

        if (self::$customFormatsCache !== null) {
            $this->customFormats = self::$customFormatsCache;
            return $this->customFormats;
        }

        $config = $this->loadConfig();
        $url = $config['custom_formats_url'] ?? '';

        if (!is_string($url) || $url === '') {
            throw new RuntimeException('TRaSH-Guides custom formats URL not configured');
        }

        $this->logger?->info('Fetching TRaSH-Guides custom formats', ['url' => $url]);

        $json = $this->fetchUrl($url);
        $data = $this->parseJson($json);

        self::$customFormatsCache = $data;
        self::$cacheTimestamp = (int) (hrtime(true) / 1_000_000_000);

        $this->customFormats = $data;
        return $this->customFormats;
    }

    /**
     * Returns the git commit SHA of the imported TRaSH-Guides version.
     *
     * @return string The git SHA (40 hex characters).
     * @throws RuntimeException On network or parsing errors.
     */
    public function getVersion(): string
    {
        $this->ensureCacheValid();

        if ($this->version !== null) {
            return $this->version;
        }

        if (self::$versionCache !== null) {
            $this->version = self::$versionCache;
            return $this->version;
        }

        $config = $this->loadConfig();
        $customFormatsUrl = $config['custom_formats_url'] ?? '';

        if (!is_string($customFormatsUrl) || $customFormatsUrl === '') {
            throw new RuntimeException('TRaSH-Guides custom formats URL not configured');
        }

        // The version is typically embedded in the JSON, or we derive it from the URL.
        $json = $this->fetchUrl($customFormatsUrl);
        $data = $this->parseJson($json);

        // TRaSH-Guides JSON often contains a version field
        /** @var string */
        $version = $data['version'] ?? $this->deriveVersionFromUrl($customFormatsUrl);

        self::$versionCache = $version;
        self::$cacheTimestamp = (int) (hrtime(true) / 1_000_000_000);

        $this->version = $version;
        return $this->version;
    }

    /**
     * Clears the cache, forcing the next request to fetch fresh data.
     *
     * Note the process-wide reach: the backing caches are `static`, so this
     * flushes the cached view for EVERY TrashGuidesProvider instance in the
     * process, not just the receiver's (it also resets the receiver's own
     * parsed copies).
     *
     * @return void
     */
    public function clearCache(): void
    {
        self::$qualityProfilesCache = null;
        self::$customFormatsCache = null;
        self::$versionCache = null;
        self::$cacheTimestamp = null;
        $this->qualityProfiles = null;
        $this->customFormats = null;
        $this->version = null;
    }

    /**
     * Ensures the cache is still valid (not expired).
     *
     * @return void
     */
    private function ensureCacheValid(): void
    {
        if (self::$cacheTimestamp === null) {
            return;
        }

        if ((int) (hrtime(true) / 1_000_000_000) - self::$cacheTimestamp > self::CACHE_TTL_SECONDS) {
            $this->clearCache();
        }
    }

    /**
     * Loads the trash_guides configuration.
     *
     * @return array<array-key, mixed> Configuration array.
     */
    private function loadConfig(): array
    {
        $configPath = dirname(__DIR__, 2) . '/config/trash_guides.php';
        if (!file_exists($configPath)) {
            // INERT BY DEFAULT (class header, LW-061 forensics): these
            // consolidated upstream files do not exist at any probed ref, so
            // the fetch legs fail closed unless a config supplies live URLs.
            // The literals are exact-pinned by the unit suite; changing them
            // is the payload-redesign lane, not this one.
            return [
                'enabled' => false,
                'auto_sync_interval' => 86400,
                'custom_formats_url' => 'https://raw.githubusercontent.com/TRaSH-Guides'
                    . '/Guides/main/docs/json/radarr/'
                    . 'radarr-collection-of-custom-formats.json',
                'quality_profiles_url' => 'https://raw.githubusercontent.com/TRaSH-Guides'
                    . '/Guides/main/docs/json/radarr/'
                    . 'radarr-setup-quality-profiles-parent.json',
            ];
        }

        // LW-061: the shape promise is a CHECK now — an included file that
        // returns anything but an array fails loud, naming the path.
        /** @var mixed $included */
        $included = include $configPath;

        return $this->requireConfigArray($included, $configPath);
    }

    /**
     * Guard for {@see self::loadConfig()}: an included config must return an
     * array, or the read is rejected at its source instead of propagating a
     * phantom shape into the sync chain.
     *
     * @param mixed  $config     Raw value returned by the included file.
     * @param string $configPath Path named in the rejection message.
     *
     * @return array<array-key, mixed> The verified array.
     *
     * @throws RuntimeException When $config is not an array.
     */
    private function requireConfigArray(mixed $config, string $configPath): array
    {
        if (!is_array($config)) {
            throw new RuntimeException(
                'TRaSH-Guides config "' . $configPath . '" must return an array, got '
                . gettype($config) . '.'
            );
        }

        return $config;
    }

    /**
     * Fetches content from a URL over a blocking stream GET, walking
     * redirects manually.
     *
     * https-only by policy: the URL is config-supplied, and a poisoned config
     * must not be able to point this fetch at local stream wrappers. LW-061
     * moved the scheme gate from "checked once, then the wrapper followed
     * Locations unseen" to "checked on every hop": the stream does not
     * auto-follow at all, each 3xx target is resolved to an absolute URL and
     * re-passes the same gate before it is dialed.
     *
     * @param string $url The URL to fetch.
     * @return string The response body.
     * @throws RuntimeException On a non-https URL (initial or redirect target),
     *                          an exhausted redirect budget, or a network error.
     */
    private function fetchUrl(string $url): string
    {
        $current = $this->requireHttpsUrl($url);

        for ($hop = 0;; $hop++) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 30,
                    // LW-061: no wrapper auto-follow — this loop validates
                    // and dials every hop itself.
                    'follow_location' => 0,
                    'header' => [
                        'Accept: application/json',
                        'User-Agent: Phlix-Media-Server/' . Version::VERSION,
                    ],
                ],
            ]);

            [$body, $responseHeaders] = $this->readUrlBody($current, $context);

            $target = $this->redirectTargetFromHeaders($responseHeaders, $current);
            if ($target === null) {
                return $body;
            }

            if ($hop >= self::MAX_REDIRECT_HOPS) {
                throw new RuntimeException(
                    'Failed to fetch TRaSH-Guides data from: ' . $url
                    . ' (exceeded ' . self::MAX_REDIRECT_HOPS . ' redirect hops)'
                );
            }

            try {
                $current = $this->requireHttpsUrl($target);
            } catch (RuntimeException $refusal) {
                throw new RuntimeException(
                    'Failed to fetch TRaSH-Guides data from: ' . $url
                    . ' (redirect hop ' . ($hop + 1) . ': ' . $refusal->getMessage() . ')',
                    0,
                    $refusal
                );
            }
        }
    }

    /**
     * The M-SHARED-22 gate, applicable to every hop: refuses anything that is
     * not https:// before it reaches a stream call, so a poisoned config — or
     * a poisoned redirect — cannot turn this fetch into a local read
     * (`file://`, `php://`, …) whose content lands in the *arr supply chain.
     *
     * @throws RuntimeException When the candidate is not an https URL.
     */
    private function requireHttpsUrl(string $candidate): string
    {
        if (!str_starts_with($candidate, 'https://')) {
            /** @var string|null $scheme */
            $scheme = parse_url($candidate, PHP_URL_SCHEME);
            throw new RuntimeException(
                'TRaSH-Guides fetch refused: only https:// URLs are allowed (got scheme "'
                . ($scheme ?? 'none') . '")'
            );
        }

        return $candidate;
    }

    /**
     * One blocking stream read with the wrapper's voice captured.
     *
     * LW-061 retires the `@`-suppression: the wrapper warning is recorded and
     * chained as the RuntimeException's previous — kept out of the public
     * message (raw wrapper text can embed internal detail, M-SHARED-22), but
     * never dropped.
     *
     * @param resource $context Stream context for the read.
     *
     * @return array{0: string, 1: list<string>} Response body and the response
     *         header stack the wrapper reported.
     *
     * @throws RuntimeException When the read fails.
     */
    private function readUrlBody(string $url, mixed $context): array
    {
        /** @var list<string> $http_response_header */
        $http_response_header = [];
        $capturedError = null;

        set_error_handler(static function (int $severity, string $message) use (&$capturedError): bool {
            $capturedError = new ErrorException($message, 0, $severity);

            return true;
        });
        try {
            /** @var string|false $body */
            $body = file_get_contents($url, false, $context);
            /** @var list<string> $responseHeaders */
            $responseHeaders = $http_response_header;
        } finally {
            restore_error_handler();
        }

        if ($body === false) {
            throw new RuntimeException('Failed to fetch TRaSH-Guides data from: ' . $url, 0, $capturedError);
        }

        return [$body, $responseHeaders];
    }

    /**
     * Resolve the next hop from a response header stack (pure; LW-061).
     *
     * Returns the absolute URL to follow, or null when the exchange is final:
     * a non-3xx status, a 304, or any response without a usable Location.
     * The LAST status line decides — an earlier hop's Location must not
     * outlive a later final response. This helper reports targets; the
     * scheme law is enforced by {@see self::requireHttpsUrl()} at the call
     * site, which is what makes a downgrade (https → http) a refusal rather
     * than a hop.
     *
     * @param list<string> $responseHeaders Stack as the wrapper reported it.
     * @param string       $fromUrl         The URL just requested (resolution base).
     */
    private function redirectTargetFromHeaders(array $responseHeaders, string $fromUrl): ?string
    {
        $status = 0;
        $location = null;

        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/[^ ]+ +(\d{3})#', $header, $statusMatch) === 1) {
                $status = (int) $statusMatch[1];
                $location = null; // a new response resets the previous Location
                continue;
            }
            if (preg_match('#^Location:[ \t]*(.*\S)[ \t]*$#i', $header, $locationMatch) === 1) {
                $location = $locationMatch[1];
            }
        }

        // `(.*\S)` above guarantees a non-empty Location when it matched.
        if ($status < 300 || $status >= 400 || $status === 304 || $location === null) {
            return null;
        }

        $hash = strpos($location, '#');
        $stripped = $hash === false ? $location : substr($location, 0, $hash);

        return $stripped === '' ? null : $this->absoluteUrl($fromUrl, $stripped);
    }

    /**
     * Resolve $target against $base (pure; LW-061), reduced to the four
     * reference forms raw/CDN redirects emit: absolute-with-scheme (kept as
     * given — the caller's https gate polices it), protocol-relative (takes
     * the request scheme), root-relative (anchors on the authority), and
     * path-relative (resolves against the base directory).
     */
    private function absoluteUrl(string $base, string $target): string
    {
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $target) === 1) {
            return $target;
        }

        /** @var array<string, mixed>|false $parts */
        $parts = parse_url($base);
        $scheme = is_array($parts) && isset($parts['scheme']) && is_string($parts['scheme'])
            ? $parts['scheme']
            : 'https';

        if (str_starts_with($target, '//')) {
            return $scheme . ':' . $target;
        }

        $host = is_array($parts) && isset($parts['host']) && is_string($parts['host'])
            ? $parts['host']
            : '';
        $port = is_array($parts) && isset($parts['port']) && is_int($parts['port'])
            ? ':' . $parts['port']
            : '';
        $authority = $scheme . '://' . $host . $port;

        if (str_starts_with($target, '/')) {
            return $authority . $target;
        }

        $basePath = is_array($parts) && isset($parts['path']) && is_string($parts['path'])
            ? $parts['path']
            : '';
        $slash = strrpos($basePath, '/');
        if ($slash === false) {
            return $authority . '/' . $target;
        }

        return $authority . substr($basePath, 0, $slash + 1) . $target;
    }

    /**
     * Parses JSON string into an array.
     *
     * @param string $json The JSON string to parse.
     * @return array<string, mixed> Parsed data.
     * @throws RuntimeException On invalid JSON.
     */
    private function parseJson(string $json): array
    {
        /** @var array<string, mixed>|false $data */
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response from TRaSH-Guides');
        }

        return $data;
    }

    /**
     * Derives a version string from the GitHub raw URL.
     *
     * @param string $url The raw URL containing branch/commit info.
     * @return string The derived version string.
     */
    private function deriveVersionFromUrl(string $url): string
    {
        // URL format: .../Guides/main/docs/json/radarr/... or .../Guides/{commit}/docs/...
        // Try to extract the commit SHA from the URL path
        if (preg_match('/Guides\/([a-f0-9]{7,40})/', $url, $matches)) {
            return $matches[1];
        }

        // Fallback: use a wall-clock (Unix epoch) timestamp as a human-meaningful
        // version indicator. This value may be logged/displayed and compared across
        // process restarts, so it must be real calendar time — hrtime(true) is
        // monotonic from an arbitrary reference point (e.g. system boot) and would
        // produce meaningless/inconsistent "version" labels here.
        return 'unknown-' . time();
    }
}
