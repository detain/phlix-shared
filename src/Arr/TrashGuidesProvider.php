<?php

/**
 * Trash Guides Provider.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Arr;

use Phlix\Shared\Version;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Fetches and parses TRaSH-Guides quality profiles and custom formats JSON.
 *
 * REAL NETWORK CODE, OUTSIDE THE TRANSPORT SEAM: `fetchUrl()` performs its own
 * blocking `@file_get_contents()` stream fetch (30 s timeout) — it does NOT go
 * through {@see \Phlix\Shared\Arr\Transport\ArrTransportInterface}, so there is
 * nothing to inject; in resident workers (phlix-server constructs this class
 * directly and `CustomFormatSyncer` consumes it) the fetch blocks the worker.
 * Routing these fetches through an injectable transport is the honest fix but
 * is a coordinated consumer-side cascade, deferred as an owner/tag-wave item.
 *
 * KNOWN LIMITATIONS (documented behavior, each verified against the code):
 * - The three static caches (`$qualityProfilesCache`, `$customFormatsCache`,
 *   `$versionCache`) share ONE `$cacheTimestamp` that every populator resets,
 *   so refreshing item B extends item A's apparent age — worst-case staleness
 *   is ~2x the advertised 24 h TTL, and any item fetched while a sibling
 *   refreshes never expires on its own schedule.
 * - The caches are `static` (process-wide): `clearCache()` on one instance
 *   flushes every other instance's cached view; per-instance isolation is
 *   NOT provided.
 * - `loadConfig()` looks for `config/trash_guides.php` relative to THIS
 *   package's own root — i.e. `vendor/detain/phlix-shared/config/` in consumer
 *   context, which ships no `config/` directory. The override is therefore
 *   unreachable for consumers: the hardcoded fallback URLs are effectively
 *   constants unless the vendor tree itself is edited.
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
        if ($this->qualityProfiles !== null) {
            return $this->qualityProfiles;
        }

        $this->ensureCacheValid();

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
        if ($this->customFormats !== null) {
            return $this->customFormats;
        }

        $this->ensureCacheValid();

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
        if ($this->version !== null) {
            return $this->version;
        }

        $this->ensureCacheValid();

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
     * @return array<string, mixed> Configuration array.
     */
    private function loadConfig(): array
    {
        $configPath = dirname(__DIR__, 2) . '/config/trash_guides.php';
        if (!file_exists($configPath)) {
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

        /** @var array<string, mixed> */
        return include $configPath;
    }

    /**
     * Fetches content from a URL using file_get_contents with stream context.
     *
     * https-only by policy: the URL is config-supplied, and a poisoned config
     * must not be able to point this fetch at local stream wrappers.
     *
     * @param string $url The URL to fetch.
     * @return string The response body.
     * @throws RuntimeException On a non-https URL, or on network errors.
     */
    private function fetchUrl(string $url): string
    {
        // Defense-in-depth (M-SHARED-22): the URL arrives from config, whose
        // default is pinned to raw.githubusercontent.com over https. A
        // poisoned config file must not be able to turn this fetch into a
        // local read (`file://`, `php://`, …) whose content lands in the
        // *arr supply chain — https only, checked before any stream call.
        if (!str_starts_with($url, 'https://')) {
            /** @var string|null $scheme */
            $scheme = parse_url($url, PHP_URL_SCHEME);
            throw new RuntimeException(
                'TRaSH-Guides fetch refused: only https:// URLs are allowed (got scheme "'
                . ($scheme ?? 'none') . '")'
            );
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'follow_location' => true,
                'max_redirects' => 5,
                'header' => [
                    'Accept: application/json',
                    'User-Agent: Phlix-Media-Server/' . Version::VERSION,
                ],
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            // The raw PHP wrapper message can embed internal detail; keep the
            // failure fact, drop the leak (M-SHARED-22).
            throw new RuntimeException('Failed to fetch TRaSH-Guides data from: ' . $url);
        }

        return $body;
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
