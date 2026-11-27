<?php

/**
 * TRaSH-Guides provider.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Arr;

use ErrorException;
use InvalidArgumentException;
use Phlix\Shared\Version;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Fetches and parses TRaSH-Guides quality profiles and custom formats JSON.
 *
 * TRANSPORT SEAM (phlix-shared-009): every fetch hop routes through the
 * injectable per-hop callable the constructor takes (`$transport`), so resident
 * workers (phlix-server constructs this class directly and
 * `CustomFormatSyncer` consumes it) can hand the dial to a non-blocking client
 * instead of blocking the worker. The seam deliberately does NOT reuse
 * {@see \Phlix\Shared\Arr\Transport\ArrTransportInterface}: its request() shape
 * carries no response headers, and this class must re-validate every redirect
 * `Location` itself. A transport answers each hop with its raw response — body
 * plus the header stack including the status line, in `$http_response_header`
 * form — and MUST NOT auto-follow redirects: following is this class's job, so
 * the per-hop https gate, hop budget, and byte cap stay load-bearing on the
 * injected path too. Seam results are parsed at this boundary (shape
 * violations fail loud) and a final response must carry a 2xx status before its
 * body is returned onward — a final 404/3xx used to flow on as if it were the
 * document. With no transport injected the class performs its own blocking
 * stream read, now bounded by ONE overall fetch deadline (default 30 s)
 * threaded into each hop as that hop's timeout — previously every hop renewed
 * a fresh 30-second activity timeout, so a slow redirect chain could stall a
 * worker indefinitely. The wrapper warning is no longer captured by swapping
 * the GLOBAL error handler for the duration of the read (a cross-coroutine
 * hazard while held): the failure diagnostic rides the exception chain from
 * error_get_last() instead. And the config `enabled` key is honoured end to
 * end — absent or truthy keeps the fetch ladders live, an explicit false
 * refuses every cold path before anything is dialed; the knob is no longer
 * phantom in either direction.
 * The class was hardened in place (2026-11-06): wrapper
 * auto-follow is OFF — every redirect target is resolved from the response
 * headers and re-validated against the https gate before the next hop is
 * dialed (hop budget 5, the stream setting it replaces), and fetch failures
 * are no longer `@`-silenced — the wrapper warning rides the exception chain
 * while public messages keep their stable, leak-free form.
 *
 * KNOWN LIMITATIONS (documented behavior, each verified against the code):
 * - The three static caches (`$qualityProfilesCache`, `$customFormatsCache`,
 *   `$versionCache`) share ONE `$cacheTimestamp` that every populator resets,
 *   so refreshing item B extends item A's apparent age — worst-case staleness
 *   is ~2x the advertised 24 h TTL, and any item fetched while a sibling
 *   refreshes never expires on its own schedule.
 * - The same shared timestamp reaches the per-instance memos: every getter
 *   runs the TTL check BEFORE the memo hit (cured 2026-11-06 — a lone
 *   instance now refetches once its 24 h is up),
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
 *   and the actual type — previously its `@var` promise was a
 *   comment, and a bare `return;` flowed onward as a phantom shape.
 * - The fallback URLs are INERT BY UPSTREAM REALITY, not merely mutable
 *   (forensics 2026-11-06): the consolidated files they name do not
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
     * Redirect hops one fetch may walk. Mirrors the `max_redirects`
     * stream setting the manual redirect loop replaced; the budget, not the
     * wrapper, is enforced so every intermediate target passes the https gate.
     */
    private const int MAX_REDIRECT_HOPS = 5;

    /**
     * Byte ceiling on one response body: 32 MiB. PHP's http stream
     * context has no response-size option, so the cap is enforced on the
     * read itself — at most MAX + 1 bytes are pulled, and a body that reaches
     * MAX + 1 is refused before it can be buffered further or JSON-decoded.
     * Every hop (redirect bodies included) is read under the same ceiling.
     */
    private const int MAX_RESPONSE_BYTES = 33_554_432;

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

    /**
     * One fetch's total budget across the whole redirect walk, in seconds.
     * Threaded into each hop as that hop's remaining-time timeout, so a slow
     * chain of hops cannot renew the budget and stall a resident worker
     * indefinitely (phlix-shared-009).
     */
    private const float FETCH_DEADLINE_SECONDS = 30.0;

    private ?LoggerInterface $logger;

    /**
     * Optional injectable per-hop transport (phlix-shared-009 seam).
     *
     * Intended signature `fetch(string $url, float $timeoutSeconds):
     * array{body: string, headers: list<string>}`. The transport answers ONE
     * hop and must NOT auto-follow redirects: redirect resolution and the
     * https re-validation are this class's responsibility, so the seam
     * inherits the per-hop policy rather than bypassing it. The promise is
     * deliberately NOT typed tighter than `mixed` above: the callable is
     * third-party code, so its result shape is validated at runtime by
     * {@see self::requireTransportResult()}, not assumed statically.
     *
     * @var (callable(string, float): mixed)|null
     */
    private $transport;

    /** @var float Total fetch budget in seconds for one walk (constructor-overridable). */
    private float $fetchDeadlineSeconds;

    /**
     * Creates a new TrashGuidesProvider instance.
     *
     * @param LoggerInterface|null $logger Optional logger instance.
     * @param (callable(string $url, float $timeoutSeconds): mixed)|null $transport
     *        Optional per-hop fetch seam. When null the class reads each hop
     *        through its own bounded blocking stream; when given, every hop is
     *        routed through it so a resident process can supply a non-blocking
     *        client. The callable receives the URL and the remaining deadline
     *        seconds for that hop; its result is validated at runtime (see
     *        the property docs), and it must answer ONE hop WITHOUT following
     *        redirects itself.
     * @param float|null $fetchDeadlineSeconds Optional override of the overall
     *        fetch deadline ({@see self::FETCH_DEADLINE_SECONDS}); must be > 0.
     *
     * @throws InvalidArgumentException When the deadline override is not positive.
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?callable $transport = null,
        ?float $fetchDeadlineSeconds = null
    ) {
        if ($fetchDeadlineSeconds !== null && $fetchDeadlineSeconds <= 0.0) {
            throw new InvalidArgumentException(
                'TRaSH-Guides fetch deadline must be a positive number of seconds, got '
                . $fetchDeadlineSeconds . '.'
            );
        }

        $this->logger = $logger;
        $this->transport = $transport;
        $this->fetchDeadlineSeconds = $fetchDeadlineSeconds ?? self::FETCH_DEADLINE_SECONDS;
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
        $this->requireEnabled($config);
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
        $this->requireEnabled($config);
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
        $this->requireEnabled($config);
        $customFormatsUrl = $config['custom_formats_url'] ?? '';

        if (!is_string($customFormatsUrl) || $customFormatsUrl === '') {
            throw new RuntimeException('TRaSH-Guides custom formats URL not configured');
        }

        // The version is typically embedded in the JSON, or we derive it from the URL.
        $json = $this->fetchUrl($customFormatsUrl);
        $data = $this->parseJson($json);

        $version = $this->resolveVersion($data, $customFormatsUrl);

        self::$versionCache = $version;
        self::$cacheTimestamp = (int) (hrtime(true) / 1_000_000_000);

        $this->version = $version;
        return $this->version;
    }

    /**
     * Picks the version string from a decoded custom-formats payload, gated
     * BEFORE it can reach `$versionCache`.
     *
     * The upstream `version` field is untrusted JSON: it used to flow through
     * an unverified `@var string` straight into a `?string` static, so an
     * integer died as a TypeError and an empty string was cached as a valid
     * "version" for 24 h. Now: an absent or null field derives from the URL
     * (unchanged fallback); a present field must be a non-empty string or the
     * read fails loud, naming the actual type, with nothing cached.
     *
     * @param array<string, mixed> $data Decoded custom-formats payload.
     * @param string               $url  The URL it came from (derivation base).
     *
     * @return non-empty-string The verified version string.
     *
     * @throws RuntimeException When the payload carries a non-string or empty
     *                          `version` field.
     */
    private function resolveVersion(array $data, string $url): string
    {
        $version = $data['version'] ?? null;
        if ($version === null) {
            $version = $this->deriveVersionFromUrl($url);
        }

        if (!is_string($version) || $version === '') {
            throw new RuntimeException(
                'TRaSH-Guides custom formats "version" field must be a non-empty string, got '
                . (is_string($version) ? 'empty string' : get_debug_type($version)) . '.'
            );
        }

        return $version;
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
            // INERT BY UPSTREAM REALITY (class header forensics): these
            // consolidated upstream files do not exist at any probed ref, so
            // the fetch legs fail closed on 404s unless a config supplies live
            // URLs. The literals are exact-pinned by the unit suite; changing
            // them is the payload-redesign lane, not this one.
            // 'enabled' is TRUE here (phlix-shared-009): the key was phantom
            // before — nothing read it — and every consumer that has ever run
            // this class ran with the fetch ladders live. Keeping that shape
            // (opt-OUT knob, mirroring the requireEnabled gate) is the
            // compatibility choice; fail-closed-at-config would have silently
            // disabled server syncs whose configs omit the key.
            return [
                'enabled' => true,
                'auto_sync_interval' => 86400,
                'custom_formats_url' => 'https://raw.githubusercontent.com/TRaSH-Guides'
                    . '/Guides/main/docs/json/radarr/'
                    . 'radarr-collection-of-custom-formats.json',
                'quality_profiles_url' => 'https://raw.githubusercontent.com/TRaSH-Guides'
                    . '/Guides/main/docs/json/radarr/'
                    . 'radarr-setup-quality-profiles-parent.json',
            ];
        }

        // The shape promise is a CHECK now — an included file that
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
     * The `enabled` knob, wired (phlix-shared-009 — the key used to be
     * phantom: nothing anywhere read it, so an operator who wrote
     * `'enabled' => false` to silence outbound fetches kept getting them).
     *
     * Posture is opt-OUT: absent or truthy keeps the fetch ladders live, which
     * is the observable behavior of every configuration that has ever run this
     * class (consumer configs that omit the key must not silently lose their
     * syncs). An explicit falsey value refuses every cold-path ladder BEFORE
     * anything is dialed — the gate runs after config load and before the URL
     * read, so populating memos and fresh caches are untouched (data already
     * in hand still serves). The truthiness follows the sibling Arr factory
     * law (`!($config[...] ?? …)`), so 0 and '' mute exactly like false.
     *
     * @param array<array-key, mixed> $config The loaded configuration.
     *
     * @throws RuntimeException When the config explicitly disables fetching.
     */
    private function requireEnabled(array $config): void
    {
        $enabled = $config['enabled'] ?? true;

        if (!$enabled) {
            throw new RuntimeException(
                'TRaSH-Guides fetching is disabled (config "enabled" is false)'
            );
        }
    }

    /**
     * Fetches content from a URL, walking redirects manually — over the
     * injected transport seam when one exists, else over a blocking stream GET.
     *
     * https-only by policy: the URL is config-supplied, and a poisoned config
     * must not be able to point this fetch at local stream wrappers. The scheme gate
     * moved from "checked once, then the wrapper followed
     * Locations unseen" to "checked on every hop": neither mechanism
     * auto-follows at all; each 3xx target is resolved to an absolute URL and
     * re-passes the same gate before it is dialed or handed to a seam.
     *
     * The whole walk runs on ONE deadline ({@see self::FETCH_DEADLINE_SECONDS},
     * constructor-overridable): each hop gets only the remaining budget as its
     * timeout, and an exhausted budget throws before another dial is made
     * (phlix-shared-009 — previously the per-hop 30 s renewed forever).
     *
     * Every hop's body is read under a byte ceiling ({@see self::MAX_RESPONSE_BYTES}):
     * an oversize response is refused instead of buffered whole — on the seam
     * path too. A final (non-redirect) response must carry a 2xx status: the
     * old code returned a 404 or unanswered-3xx body onward as if it were the
     * document, letting a dead upstream feed garbage into the *arr sync chain.
     *
     * @param string $url      The URL to fetch.
     * @param int    $maxBytes Body byte ceiling per hop (the constant in
     *                         production; tests pass a small one).
     * @return string The response body.
     * @throws RuntimeException On a non-https URL (initial or redirect target),
     *                          an exhausted redirect budget, an exhausted fetch
     *                          deadline, a seam result that violates its shape,
     *                          an oversize body, a non-2xx final status, or a
     *                          network error.
     */
    private function fetchUrl(string $url, int $maxBytes = self::MAX_RESPONSE_BYTES): string
    {
        $current = $this->requireHttpsUrl($url);
        $deadlineAtNs = (int) hrtime(true) + (int) ($this->fetchDeadlineSeconds * 1_000_000_000);

        for ($hop = 0;; $hop++) {
            $remainingSeconds = ($deadlineAtNs - (int) hrtime(true)) / 1_000_000_000;
            if ($remainingSeconds <= 0.0) {
                throw new RuntimeException(
                    'Failed to fetch TRaSH-Guides data from: ' . $url
                    . ' (overall ' . $this->fetchDeadlineSeconds
                    . '-second fetch deadline elapsed before hop ' . ($hop + 1) . ')'
                );
            }

            [$body, $responseHeaders] = $this->fetchOneHop($current, $remainingSeconds, $maxBytes);

            $target = $this->redirectTargetFromHeaders($responseHeaders, $current);
            if ($target === null) {
                $status = $this->statusFromHeaders($responseHeaders);
                if ($status < 200 || $status > 299) {
                    throw new RuntimeException(
                        'Failed to fetch TRaSH-Guides data from: ' . $url
                        . ' (final response status ' . $status . ')'
                    );
                }

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
     * One hop, seam-first: an injected transport answers it, otherwise the
     * class performs its own bounded blocking stream read. Both mechanisms
     * return the same parsed shape (body + raw header stack); the hop walk,
     * https gate, byte cap, deadline, and 2xx law live in the caller, so a
     * seam cannot route around any of them.
     *
     * @param float $remainingSeconds Deadline budget left for this hop —
     *                                handed to the seam and used as the
     *                                stream timeout alike.
     *
     * @return array{0: string, 1: list<string>}
     *
     * @throws RuntimeException On a read failure or a seam shape violation.
     */
    private function fetchOneHop(string $url, float $remainingSeconds, int $maxBytes): array
    {
        if ($this->transport !== null) {
            /** @var mixed $result */
            $result = ($this->transport)($url, $remainingSeconds);

            return $this->requireTransportResult($result, $url, $maxBytes);
        }

        return $this->readUrlBody($url, $this->createStreamContext($remainingSeconds), $maxBytes);
    }

    /**
     * Boundary parse of one seam result (phlix-shared-009): the injected
     * callable is third-party code, so its promise is a CHECK, not a comment —
     * a non-array result, a missing/non-string body, a header stack that is
     * not a list of raw strings, or an over-cap body all fail loud with the
     * stable public prefix instead of flowing into redirect parsing or
     * JSON-decode.
     *
     * @param mixed $result   Raw seam return value.
     * @param int   $maxBytes The per-hop byte ceiling enforced on this hop.
     *
     * @return array{0: string, 1: list<string>} Verified body and header stack.
     *
     * @throws RuntimeException On any shape or cap violation.
     */
    private function requireTransportResult(mixed $result, string $url, int $maxBytes): array
    {
        $refusal = static function (string $why) use ($url): RuntimeException {
            return new RuntimeException(
                'Failed to fetch TRaSH-Guides data from: ' . $url . ' (' . $why . ')'
            );
        };

        if (!is_array($result)) {
            throw $refusal('transport seam returned ' . get_debug_type($result) . ', expected an array');
        }

        $body = $result['body'] ?? null;
        if (!is_string($body)) {
            throw $refusal(
                'transport seam "body" must be a string, got ' . get_debug_type($body)
            );
        }

        $headers = $result['headers'] ?? null;
        if (!is_array($headers) || !array_is_list($headers)) {
            throw $refusal('transport seam "headers" must be a list of raw header strings');
        }
        foreach ($headers as $header) {
            if (!is_string($header)) {
                throw $refusal('transport seam "headers" must be a list of raw header strings');
            }
        }

        /** @var list<string> $headers */
        $maxBytes = max(1, $maxBytes);
        if (strlen($body) > $maxBytes) {
            throw $refusal('response exceeds the ' . $maxBytes . '-byte cap');
        }

        return [$body, $headers];
    }

    /**
     * Stream context for one hop of the default (no-seam) mechanism: GET,
     * wrapper auto-follow OFF (the caller validates every hop), the REMAINING
     * overall deadline as this hop's activity timeout, and the honest
     * Accept/User-Agent headers.
     *
     * @return resource
     */
    private function createStreamContext(float $remainingSeconds)
    {
        return stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $remainingSeconds,
                // No wrapper auto-follow — this loop validates
                // and dials every hop itself.
                'follow_location' => 0,
                'header' => [
                    'Accept: application/json',
                    'User-Agent: Phlix-Media-Server/' . Version::VERSION,
                ],
            ],
        ]);
    }

    /**
     * The LAST status line in a response header stack (pure) — an earlier
     * hop's status must not outlive a later response. 0 when the stack
     * carries none, which is not a 2xx and so refuses the exchange.
     *
     * @param list<string> $responseHeaders Stack as the wrapper or seam reported it.
     */
    private function statusFromHeaders(array $responseHeaders): int
    {
        $status = 0;

        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/[^ ]+ +(\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return $status;
    }

    /**
     * The https gate, applicable to every hop: refuses anything that is
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
     * One blocking stream read (the no-seam default path) with the wrapper's
     * voice captured — WITHOUT touching the global error-handler chain.
     *
     * The prior implementation wrapped the read in
     * `set_error_handler(... ) / restore_error_handler()`. That is hostile to
     * every other coroutine on the same worker (phlix-shared-009): for the
     * duration of a multi-second network read it REPLACED the application's
     * own handler, so unrelated warnings raised concurrently elsewhere were
     * swallowed by this fetch's throwaway handler and lost. The read is now
     * `@`-suppressed inline; the failure diagnostic is recovered from
     * error_get_last() and chained as the RuntimeException's previous — kept
     * out of the public message (raw wrapper text can embed internal detail)
     * but never dropped, and other code's warnings reach the application
     * handler uninterrupted.
     *
     * The read stays length-bounded — at most `$maxBytes + 1` bytes are
     * pulled, and reaching the extra byte is the oversize verdict (the body
     * is dropped, never decoded).
     *
     * @param resource $context  Stream context for the read.
     * @param int      $maxBytes Body byte ceiling (>= 1).
     *
     * @return array{0: string, 1: list<string>} Response body and the response
     *         header stack the wrapper reported.
     *
     * @throws RuntimeException When the read fails or the body exceeds $maxBytes.
     */
    private function readUrlBody(string $url, mixed $context, int $maxBytes = self::MAX_RESPONSE_BYTES): array
    {
        $maxBytes = max(1, $maxBytes);
        /** @var list<string> $http_response_header */
        $http_response_header = [];

        // The leading '@' is load-bearing: it is carried by the SHR-2
        // source-pin needle in tests/Arr/TrashGuidesProviderTest.php
        // (readUrlBody byte-cap test) and pinned behaviorally by the
        // failOnWarning harness — the memo-refetch and captured-error tests
        // trip on the raw wrapper warning without it. The handler-neutrality
        // lex keeps the global set_error_handler/restore_error_handler swap
        // dead; it does NOT observe the '@'.
        /** @var string|false $body */
        $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header;

        if ($body === false) {
            $lastError = error_get_last();
            $capturedError = $lastError === null
                ? null
                : new ErrorException($lastError['message'], 0, $lastError['type']);

            throw new RuntimeException('Failed to fetch TRaSH-Guides data from: ' . $url, 0, $capturedError);
        }

        if (strlen($body) > $maxBytes) {
            throw new RuntimeException(
                'Failed to fetch TRaSH-Guides data from: ' . $url
                . ' (response exceeds the ' . $maxBytes . '-byte cap)'
            );
        }

        return [$body, $responseHeaders];
    }

    /**
     * Resolve the next hop from a response header stack (pure).
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
     * Resolve $target against $base (pure), reduced to the four
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
