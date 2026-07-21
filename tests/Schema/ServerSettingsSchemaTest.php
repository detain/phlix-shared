<?php

/**
 * Server Settings Schema Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Schema;

use Phlix\Shared\Schema\SchemaPaths;
use PHPUnit\Framework\TestCase;

final class ServerSettingsSchemaTest extends TestCase
{
    use SettingsSchemaAssertions;

    /**
     * Decoded server-settings schema document.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $raw = (string) file_get_contents(SchemaPaths::serverSettings());
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'server-settings.schema.json must decode to a JSON object.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The decoded `properties` map of the schema.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function properties(): array
    {
        $schema = self::schema();
        self::assertArrayHasKey('properties', $schema);
        self::assertIsArray($schema['properties']);

        $properties = [];
        foreach ($schema['properties'] as $key => $value) {
            self::assertIsString($key);
            self::assertIsArray($value, sprintf('Property "%s" must be a JSON object.', $key));
            /** @var array<string, mixed> $value */
            $properties[$key] = $value;
        }

        return $properties;
    }

    /**
     * The canonical built-in noise-suffix default list (longest phrase first).
     *
     * Mirrors phlix-server's TitleSuffixStripper::NOISE_SUFFIXES; the schema declares
     * this list as the `default` for the `matching.noise_suffixes` setting.
     *
     * @var list<string>
     */
    private const NOISE_SUFFIX_DEFAULTS = [
        'unrated directors cut',
        'uncut & unrated',
        'alternate ending',
        'extended cut',
        'directors cut',
        "director's cut",
        'theatrical cut',
        'remastered',
        'extended',
        'uncut',
        'yify',
        'dc',
    ];

    /**
     * The expected property keys mapped to their JSON-Schema type.
     *
     * Every key here MUST be a dotted path that
     * `Phlix\Admin\SettingsRepository::getDefault()` can resolve in
     * phlix-server: a leading run of segments names a config file under
     * `config/` (subdirectories ARE supported since the nested-path resolver
     * landed — `config/scrobblers/trakt.php` is reachable as
     * `scrobblers.trakt.*`, longest file path winning) and the remaining
     * segments index into that file's array. The cross-repo resolvability
     * assertion itself lives in phlix-server, which owns the `config/` tree.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function propertyProvider(): array
    {
        return [
            // config/hwaccel.php (delegates to config/hwaccel_base.php)
            'hwaccel.enabled' => ['hwaccel.enabled', 'boolean'],
            'hwaccel.prefer_hardware' => ['hwaccel.prefer_hardware', 'boolean'],
            // NOTE: `hwaccel.probe_timeout` was DELETED in 0.26.0. It resolved to a
            // config default but had no consumer: the real hwaccel probe timeouts are
            // the hardcoded ShellTimeout::FFMPEG_TIMEOUT (10) / ::GPU_TOOL_TIMEOUT (5)
            // constants in phlix-server, reached through static calls from seven
            // VendorProbe classes that no config value is threaded into. See
            // phlix-server/docs/dev/settings-restart-gap.md. Do not re-add it without
            // first citing the file:line that reads the effective value.
            // config/transcoding.php
            'transcoding.preferred_accelerator' => ['transcoding.preferred_accelerator', 'string'],
            // NOTE: `transcoding.include_software_fallback` was DELETED in 0.27.0 for the
            // same reason as `hwaccel.probe_timeout` above: it resolved to a real config
            // default (`phlix-server/config/transcoding.php:44`) and was copied into the
            // merged array by `HwAccelConfig::get()`
            // (`phlix-server/src/Config/HwAccelConfig.php:118`), but NOTHING read the
            // merged value. The only consumers of that array are `FfmpegRunner::setConfig()`
            // — which reads exactly `tone_mapping_mode`, `prefer_hdr_output`,
            // `preferred_accelerator`, `enabled` and `prefer_hardware` — and
            // `HwaccelRegistry`, whose software-fallback decision reads the SEPARATE
            // `hwaccel.fallback_to_software` key
            // (`HwaccelRegistry.php:160,206`, sourced from `config/hwaccel_base.php`).
            // Do not re-add it without first citing the file:line that reads the
            // effective value; if a software-fallback toggle is wanted, expose
            // `hwaccel.fallback_to_software`, which is genuinely consumed.
            'transcoding.tone_mapping_mode' => ['transcoding.tone_mapping_mode', 'string'],
            'transcoding.prefer_hdr_output' => ['transcoding.prefer_hdr_output', 'boolean'],
            // config/ffmpeg.php
            'ffmpeg.max_concurrent_transcodes' => ['ffmpeg.max_concurrent_transcodes', 'integer'],
            'ffmpeg.transcode_timeout' => ['ffmpeg.transcode_timeout', 'integer'],
            'ffmpeg.max_concurrent_scan_probes' => ['ffmpeg.max_concurrent_scan_probes', 'integer'],
            // config/server.php -> 'hls'
            'server.hls.segment_seconds' => ['server.hls.segment_seconds', 'integer'],
            'server.hls.max_concurrent_segments' => ['server.hls.max_concurrent_segments', 'integer'],
            'server.hls.cache_max_age' => ['server.hls.cache_max_age', 'integer'],
            'server.hls.cache_max_bytes' => ['server.hls.cache_max_bytes', 'integer'],
            // config/tmdb.php, config/metadata.php, config/matching.php
            'tmdb.api_key' => ['tmdb.api_key', 'string'],
            'matching.noise_suffixes' => ['matching.noise_suffixes', 'array'],
            'metadata.provider_priority' => ['metadata.provider_priority', 'object'],
            'metadata.genres_mode' => ['metadata.genres_mode', 'string'],
            // config/auth.php
            'auth.signup_mode' => ['auth.signup_mode', 'string'],
            // NOTE: both `marker_detection.*` keys were DELETED in 0.28.0. They
            // resolved to real config defaults but had NO consumer: the only reads
            // of config/marker_detection.php are MediaServicesProvider.php:521,539,
            // which take `job_queue_dir` and `min_episodes_for_detection` — and do
            // so through a raw @include that bypasses EffectiveConfig entirely.
            // config/subtitles.php
            'subtitles.default_language' => ['subtitles.default_language', 'string'],
            // NOTE: `subtitles.enabled` and `subtitles.burn_in_by_default` were
            // DELETED in 0.28.0 — config/subtitles.php is composed only into
            // config/ffmpeg.php (so it lives at $config['ffmpeg']['subtitles'],
            // which these keys do not address) and NOTHING in phlix-server read
            // either identifier. `default_language` survives because it was given
            // a real consumer in the same release: the server-wide fallback for
            // preferred_subtitle_language in GET /api/v1/user/settings
            // (WebPortalRouter::defaultSubtitleLanguage()).
            // config/trickplay.php, config/newsletter.php
            // NOTE: `discovery.discovery_port` was DELETED in 0.28.0 —
            // config/discovery.php has NO loader anywhere in phlix-server, and
            // Application::startDiscoveryIfEnabled() reads no flag at all.
            'trickplay.enabled' => ['trickplay.enabled', 'boolean'],
            // NOTE: `trickplay.interval_seconds` was DELETED in 0.28.0. There are
            // TWO trickplay implementations; config/trickplay.php describes the
            // DEAD one (TrickplayGenerator + TrickplayConfig), reachable only via
            // StreamManager::generateTrickplay(), which throws unless
            // StreamManager::setTrickplay() ran — and that setter has no callers.
            // The live implementation (MediaAssetGenerationJob) has no interval
            // concept at all; it takes a fixed sprite COUNT. `trickplay.enabled`
            // survives because it was wired to that live path in the same release.
            'newsletter.enabled' => ['newsletter.enabled', 'boolean'],
            'newsletter.send_hour' => ['newsletter.send_hour', 'integer'],
            // config/port-forward.php
            'port-forward.port_forwarding.upnp_enabled' => ['port-forward.port_forwarding.upnp_enabled', 'boolean'],
            // config/lastfm.php
            'lastfm.api_key' => ['lastfm.api_key', 'string'],
            'lastfm.shared_secret' => ['lastfm.shared_secret', 'string'],
            'lastfm.enabled' => ['lastfm.enabled', 'boolean'],
            // config/trakt.php — a one-line re-export of config/scrobblers/trakt.php,
            // mirroring config/hwaccel.php's `return require __DIR__ . '/hwaccel_base.php';`.
            // The FLAT prefix is deliberate: `trakt.*` overrides are already persisted in
            // the server_settings table on live installs, and TraktOAuthController's
            // SETTING_KEY_MAP reads those exact keys. Renaming them to `scrobblers.trakt.*`
            // (which the nested-path resolver would also accept) would orphan those rows.
            'trakt.client_id' => ['trakt.client_id', 'string'],
            'trakt.client_secret' => ['trakt.client_secret', 'string'],
            'trakt.redirect_uri' => ['trakt.redirect_uri', 'string'],
            // config/relay.php
            'relay.reconnect_delay' => ['relay.reconnect_delay', 'integer'],
            'relay.ping_interval' => ['relay.ping_interval', 'integer'],
            // config/process.php — worker names are HYPHENATED
            'process.library-scan.enabled' => ['process.library-scan.enabled', 'boolean'],
            'process.plugin-auto-update.enabled' => ['process.plugin-auto-update.enabled', 'boolean'],
            'process.marker-detection.enabled' => ['process.marker-detection.enabled', 'boolean'],
            'process.media-asset.enabled' => ['process.media-asset.enabled', 'boolean'],
            'process.similarity.enabled' => ['process.similarity.enabled', 'boolean'],
        ];
    }

    /**
     * The canonical per-media-type metadata source-priority default map.
     *
     * Mirrors phlix-server's config/metadata.php (Step 3.3b) / MetadataManager
     * built-in provider-priority map; the schema declares this as the `default`
     * for the `metadata.provider_priority` setting.
     *
     * @var array<string, list<string>>
     */
    private const PROVIDER_PRIORITY_DEFAULTS = [
        'movie' => ['tmdb', 'imdb'],
        'series' => ['tmdb', 'imdb'],
        'anime' => ['anidb', 'myanimelist', 'tvdb', 'fanart', 'local'],
    ];

    /**
     * Keys that must be masked in the admin UI (`"secret": true`).
     *
     * Every service API key / shared secret belongs here; the schema is the
     * only thing standing between a credential and a plaintext form field.
     *
     * @var list<string>
     */
    private const SECRET_KEYS = [
        'tmdb.api_key',
        'lastfm.api_key',
        'lastfm.shared_secret',
        // Both halves of the Trakt application credential are masked, matching the
        // treatment of `lastfm.api_key` — an OAuth client_id is nominally public, but
        // it is the direct analogue of the Last.fm API key (Phlix sends it as the
        // `trakt-api-key` header) and this schema already masks that. Consistency wins:
        // an admin cannot tell at a glance which half of a credential pair is safe to
        // leak into a HAR capture. `trakt.redirect_uri` is a public URL and is NOT here.
        'trakt.client_id',
        'trakt.client_secret',
    ];

    /**
     * Numeric constraints (minimum/maximum) the constrained keys must carry.
     *
     * @return array<string, array{0: string, 1: array<string, int|float>}>
     */
    public static function constraintProvider(): array
    {
        return [

            'newsletter.send_hour' => ['newsletter.send_hour', ['minimum' => 0, 'maximum' => 23]],
            'ffmpeg.max_concurrent_transcodes' => ['ffmpeg.max_concurrent_transcodes', ['minimum' => 1, 'maximum' => 64]],
            'ffmpeg.transcode_timeout' => ['ffmpeg.transcode_timeout', ['minimum' => 60, 'maximum' => 86400]],
            'ffmpeg.max_concurrent_scan_probes' => ['ffmpeg.max_concurrent_scan_probes', ['minimum' => 1, 'maximum' => 16]],
            'relay.reconnect_delay' => ['relay.reconnect_delay', ['minimum' => 1, 'maximum' => 60]],
            'relay.ping_interval' => ['relay.ping_interval', ['minimum' => 5, 'maximum' => 300]],
            'server.hls.segment_seconds' => ['server.hls.segment_seconds', ['minimum' => 1, 'maximum' => 30]],
            'server.hls.max_concurrent_segments' => ['server.hls.max_concurrent_segments', ['minimum' => 1, 'maximum' => 32]],
            'server.hls.cache_max_age' => ['server.hls.cache_max_age', ['minimum' => 60, 'maximum' => 86400]],
            'server.hls.cache_max_bytes' => [
                'server.hls.cache_max_bytes',
                ['minimum' => 1073741824, 'maximum' => 1099511627776],
            ],
        ];
    }

    public function test_schema_declares_the_expected_meta_header(): void
    {
        $schema = self::schema();
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null);
        $this->assertSame('https://phlix.tv/schemas/server-settings.schema.json', $schema['$id'] ?? null);
        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertFalse($schema['additionalProperties'] ?? null);
    }

    public function test_schema_has_exactly_the_expected_property_keys(): void
    {
        $actual = array_keys(self::properties());
        $expected = array_map(
            static fn (array $row): string => $row[0],
            array_values(self::propertyProvider())
        );

        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual, 'server-settings schema must declare exactly the expected settings keys.');
        $this->assertCount(35, $actual);
    }

    /**
     * Keys deleted from this schema because they had NO CONSUMER in phlix-server.
     *
     * Each entry records the audit that removed it. A key here resolved to a real
     * `config/*.php` default — so it passed every resolvability test — yet no line
     * of `phlix-server` ever read the effective value. Per plan §4 rule 10 such a
     * key is deleted, not shipped.
     *
     * @var array<string, string>
     */
    private const CONSUMERLESS_KEY_DENYLIST = [
        'hwaccel.probe_timeout' =>
            'Deleted in 0.26.0. HwaccelRegistry is built via getInstance() with no config, '
            . 'and the real probe timeouts are the hardcoded ShellTimeout::FFMPEG_TIMEOUT (10) '
            . '/ ::GPU_TOOL_TIMEOUT (5) constants that no config value is threaded into.',
        // --- Deleted in 0.28.0 by the full 41-key sweep -------------------
        //
        // Context: plan_settings.md §11 asserted the shipped keys were "verified
        // consumed-and-reachable". A key-by-key audit disproved that for 14 of
        // 41. Eight were repaired or wired; these six could not be made honest
        // without building the feature behind them, so per §4 rule 10 they were
        // deleted rather than shipped. TWO OF THEM HAD LIVE ADMIN OVERRIDES ON
        // PRODUCTION — an operator had set a subtitle language and disabled
        // trickplay, and both did nothing.
        'marker_detection.similarity_threshold' =>
            'Deleted in 0.28.0. No consumer. The only reads of '
            . 'config/marker_detection.php are MediaServicesProvider.php:521,539 for '
            . 'job_queue_dir and min_episodes_for_detection, via a raw @include that '
            . 'bypasses EffectiveConfig. Neither schema key is read anywhere.',
        'marker_detection.intro_max_duration' =>
            'Deleted in 0.28.0. Same as marker_detection.similarity_threshold.',
        'subtitles.enabled' =>
            'Deleted in 0.28.0. config/subtitles.php is composed ONLY into '
            . 'config/ffmpeg.php:52, so it lives at $config["ffmpeg"]["subtitles"] — '
            . 'a path this key does not address — and no line of phlix-server reads '
            . 'the identifier. SubtitleExtractor has no constructor and receives no '
            . 'config at all.',
        'subtitles.burn_in_by_default' =>
            'Deleted in 0.28.0. Same as subtitles.enabled; zero occurrences of the '
            . 'identifier in src/.',
        'discovery.discovery_port' =>
            'Deleted in 0.28.0. config/discovery.php has NO loader anywhere in '
            . 'phlix-server, and Application::startDiscoveryIfEnabled() reads no flag '
            . 'before starting the server — it is also inside Application::run(), '
            . 'which has no caller. Its helpText was factually wrong too (it '
            . 'described UDP broadcast; 8200 is the DLNA HTTP port).',
        'trickplay.interval_seconds' =>
            'Deleted in 0.28.0. Configures the DEAD trickplay implementation '
            . '(TrickplayGenerator + TrickplayConfig), whose only entry point '
            . 'StreamManager::generateTrickplay() throws unless setTrickplay() ran, '
            . 'and setTrickplay() has no callers. The LIVE implementation '
            . '(MediaAssetGenerationJob) has no interval concept — it takes a fixed '
            . 'sprite count. Do not re-add without wiring an interval into the live '
            . 'path; trickplay.enabled IS wired there and is genuine.',

        'transcoding.include_software_fallback' =>
            'Deleted in 0.27.0. HwAccelConfig::get() copied it into the merged hwaccel array, '
            . 'but FfmpegRunner reads only tone_mapping_mode/prefer_hdr_output/'
            . 'preferred_accelerator/enabled/prefer_hardware from that array, and '
            . "HwaccelRegistry's software-fallback branch reads the SEPARATE "
            . 'hwaccel.fallback_to_software key. Expose that one instead.',
    ];

    /**
     * Regression guard: a key deleted for having no consumer must never come back.
     *
     * **This is deliberately a denylist, not a general consumerless-key detector.**
     * A general detector is not implementable here and would not be reliable if it
     * were:
     *
     * - `phlix-shared` has no access to `phlix-server`'s source tree at all, so the
     *   question "does any line read this key's effective value?" is unanswerable
     *   from this repo.
     * - Even inside `phlix-server` the question is a whole-program dataflow problem,
     *   not a grep. A consumed key can be read as a literal
     *   (`getEffective('auth.signup_mode')`), through a merged array handed across a
     *   DI boundary (`FfmpegRunner::setConfig()` then `$this->config['...']`), or
     *   through a variable array key (`EffectiveConfig::file('process')[$procKey]`
     *   in `start.php`). Any name-matching heuristic strong enough to catch case 3
     *   matches common leaf names like `enabled`/`timeout` everywhere and yields
     *   false PASSES; any heuristic strict enough to avoid that produces false
     *   FAILURES on cases 2 and 3, which get silenced by an allow-list that then
     *   rots into a rubber stamp.
     *
     * A guard whose allow-list must be grown every time it misfires proves nothing.
     * So the invariant asserted here is the narrow one that *is* decidable and *is*
     * the observed recurrence: the specific keys a human audit already proved dead
     * stay dead. New keys are covered by plan §4 rule 1 instead — cite the
     * `file:line` that reads the effective value, in the PR that adds the key.
     */
    public function test_no_schema_key_is_a_known_consumerless_key(): void
    {
        $properties = array_keys(self::properties());
        $expected = array_map(
            static fn (array $row): string => $row[0],
            array_values(self::propertyProvider())
        );

        foreach (self::CONSUMERLESS_KEY_DENYLIST as $key => $why) {
            $this->assertNotContains(
                $key,
                $properties,
                sprintf(
                    'Settings key "%s" was deleted because it has no consumer in phlix-server '
                    . 'and must not be re-added to server-settings.schema.json. %s '
                    . 'If you believe it is now genuinely consumed, cite the file:line that '
                    . 'reads the EFFECTIVE value and remove it from CONSUMERLESS_KEY_DENYLIST '
                    . 'in the same change.',
                    $key,
                    $why
                )
            );

            // The hand-written expectation list must not resurrect it either — a key
            // added to both places would otherwise still satisfy the key-set test.
            $this->assertNotContains(
                $key,
                $expected,
                sprintf('Consumerless key "%s" must not be re-added to propertyProvider().', $key)
            );
        }
    }

    /**
     * Guard the key-shape rules the resolver imposes on phlix-server's side.
     *
     * `SettingsRepository::loadConfig()` jails EVERY dotted file segment to
     * `/^[A-Za-z0-9_-]+$/`, so a key whose first segment embeds a `/` (or any
     * other character) can never resolve and would additionally be a path
     * traversal attempt. Nested file paths are expressed as separate dotted
     * segments (`scrobblers.trakt.client_id`), never as a literal slash.
     */
    public function test_every_key_first_segment_is_a_flat_config_file_name(): void
    {
        foreach (array_keys(self::properties()) as $key) {
            $this->assertStringContainsString('.', $key, sprintf('Setting key "%s" must be dotted.', $key));

            $segments = explode('.', $key);
            $file = $segments[0];

            $this->assertMatchesRegularExpression(
                '/^[A-Za-z0-9_-]+$/',
                $file,
                sprintf(
                    'Setting key "%s" starts with "%s", which SettingsRepository::loadConfig() would reject — the first segment must be a flat config file name.',
                    $key,
                    $file
                )
            );

            foreach (array_slice($segments, 1) as $segment) {
                $this->assertNotSame('', $segment, sprintf('Setting key "%s" must not contain an empty path segment.', $key));
            }
        }
    }

    public function test_noise_suffixes_is_an_array_of_strings_with_canonical_default(): void
    {
        $properties = self::properties();
        $this->assertArrayHasKey('matching.noise_suffixes', $properties);

        $property = $properties['matching.noise_suffixes'];

        $this->assertSame('array', $property['type'] ?? null);
        $this->assertSame('matching', $property['group'] ?? null);

        $this->assertArrayHasKey('items', $property);
        $this->assertIsArray($property['items']);
        $this->assertSame('string', $property['items']['type'] ?? null);

        $this->assertArrayHasKey('default', $property);
        $this->assertIsArray($property['default']);

        $default = $property['default'];

        // The default must be a non-empty list of distinct non-empty strings ...
        $this->assertNotEmpty($default);
        foreach ($default as $entry) {
            $this->assertIsString($entry);
            $this->assertNotSame('', $entry);
        }
        $this->assertSame(
            $default,
            array_values(array_unique($default)),
            'noise-suffix defaults must be a distinct list.'
        );

        // ... and must mirror the canonical phlix-server TitleSuffixStripper list verbatim.
        $this->assertSame(self::NOISE_SUFFIX_DEFAULTS, $default);
    }

    public function test_provider_priority_is_a_per_type_map_of_string_arrays_with_canonical_default(): void
    {
        $properties = self::properties();
        $this->assertArrayHasKey('metadata.provider_priority', $properties);

        $property = $properties['metadata.provider_priority'];

        $this->assertSame('object', $property['type'] ?? null);
        $this->assertSame('metadata', $property['group'] ?? null);

        // additionalProperties allows arbitrary media-type keys, each an array of source-name strings.
        $this->assertArrayHasKey('additionalProperties', $property);
        $this->assertIsArray($property['additionalProperties']);
        $this->assertSame('array', $property['additionalProperties']['type'] ?? null);
        $this->assertArrayHasKey('items', $property['additionalProperties']);
        $this->assertIsArray($property['additionalProperties']['items']);
        $this->assertSame('string', $property['additionalProperties']['items']['type'] ?? null);

        $this->assertArrayHasKey('default', $property);
        $this->assertIsArray($property['default']);

        $default = $property['default'];

        // The default must be a non-empty map of media-type => non-empty list of non-empty strings.
        $this->assertNotEmpty($default);
        foreach ($default as $type => $order) {
            $this->assertIsString($type);
            $this->assertNotSame('', $type);
            $this->assertIsArray($order);
            $this->assertNotEmpty($order);
            foreach ($order as $source) {
                $this->assertIsString($source);
                $this->assertNotSame('', $source);
            }
        }

        // ... and must mirror the canonical phlix-server config/metadata.php map verbatim.
        $this->assertSame(self::PROVIDER_PRIORITY_DEFAULTS, $default);
    }

    public function test_genres_mode_is_a_first_or_union_enum_defaulting_to_first(): void
    {
        $properties = self::properties();
        $this->assertArrayHasKey('metadata.genres_mode', $properties);

        $property = $properties['metadata.genres_mode'];

        $this->assertSame('string', $property['type'] ?? null);
        $this->assertSame('metadata', $property['group'] ?? null);

        $this->assertArrayHasKey('enum', $property);
        $this->assertSame(['first', 'union'], $property['enum']);

        $this->assertArrayHasKey('default', $property);
        $this->assertSame('first', $property['default']);
    }

    /**
     * The accelerator enum must use FFmpeg *hwaccel* names, not encoder names.
     *
     * `FfmpegRunner::getBestAcceleratorForCodec()` looks the configured value
     * up in the map keyed by the hwaccel names probed in
     * `probeHardwareAcceleration()`. `nvenc` is an ENCODER family
     * (`h264_nvenc`), never a hwaccel, so pinning it silently never matched;
     * likewise `v4l2` (the hwaccel is `v4l2m2m`). The empty string is the
     * auto-detect sentinel: `FfmpegRunner` only applies a preference when the
     * configured value is a non-empty string.
     */
    public function test_preferred_accelerator_enum_uses_ffmpeg_hwaccel_names(): void
    {
        $properties = self::properties();
        $this->assertArrayHasKey('transcoding.preferred_accelerator', $properties);

        $property = $properties['transcoding.preferred_accelerator'];

        $this->assertSame('string', $property['type'] ?? null);
        $this->assertSame('', $property['default'] ?? null, 'The auto-detect sentinel must be the empty string.');

        $enum = $property['enum'] ?? null;
        $this->assertIsArray($enum);
        $this->assertSame(
            ['', 'cuda', 'qsv', 'vaapi', 'videotoolbox', 'amf', 'opencl', 'd3d11va', 'dxva2', 'v4l2m2m'],
            $enum
        );
        $this->assertNotContains('nvenc', $enum, '"nvenc" is an encoder family, not an FFmpeg hwaccel name.');
        $this->assertNotContains('v4l2', $enum, 'The V4L2 hwaccel name is "v4l2m2m", not "v4l2".');
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_property_has_the_expected_json_schema_type(string $key, string $expectedType): void
    {
        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);
        $this->assertSame($expectedType, $properties[$key]['type'] ?? null);
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_property_has_non_empty_group_and_description(string $key, string $expectedType): void
    {
        // $expectedType is part of the shared provider row but not asserted here.
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);

        $group = $properties[$key]['group'] ?? null;
        $description = $properties[$key]['description'] ?? null;

        $this->assertIsString($group, sprintf('Property "%s" must have a string group.', $key));
        $this->assertNotSame('', $group, sprintf('Property "%s" group must be non-empty.', $key));

        $this->assertIsString($description, sprintf('Property "%s" must have a string description.', $key));
        $this->assertNotSame('', $description, sprintf('Property "%s" description must be non-empty.', $key));
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_property_declares_a_valid_tier(string $key, string $expectedType): void
    {
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);
        $this->assertPropertyDeclaresTier($key, $properties[$key]);
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_property_default_is_type_consistent_and_within_bounds(string $key, string $expectedType): void
    {
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);

        $this->assertDefaultMatchesDeclaredType($key, $properties[$key]);
        $this->assertDefaultIsWithinBounds($key, $properties[$key]);
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_property_enum_options_are_fully_documented(string $key, string $expectedType): void
    {
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);
        $this->assertEnumOptionsAreFullyDocumented($key, $properties[$key]);
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_optional_extended_keywords_are_well_formed(string $key, string $expectedType): void
    {
        // $expectedType is part of the shared provider row but not asserted here.
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);

        $this->assertHelpLinksAreWellFormed($key, $properties[$key]);
        $this->assertFlagKeywordsAreBooleans($key, $properties[$key]);
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_property_has_label_and_help_text(string $key, string $expectedType): void
    {
        // $expectedType is part of the shared provider row but not asserted here.
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);

        $label = $properties[$key]['label'] ?? null;
        $helpText = $properties[$key]['helpText'] ?? null;

        $this->assertIsString($label, sprintf('Property "%s" must have a string label.', $key));
        $this->assertNotSame('', $label, sprintf('Property "%s" label must be non-empty.', $key));

        $this->assertIsString($helpText, sprintf('Property "%s" must have a string helpText.', $key));
        $this->assertNotSame('', $helpText, sprintf('Property "%s" helpText must be non-empty.', $key));
    }

    public function test_credential_keys_are_marked_secret(): void
    {
        $properties = self::properties();

        foreach (self::SECRET_KEYS as $key) {
            $this->assertArrayHasKey($key, $properties);
            $this->assertTrue(
                $properties[$key]['secret'] ?? false,
                sprintf('Property "%s" holds a credential and must declare "secret": true.', $key)
            );
        }

        // ... and nothing else claims to be a secret without being listed above.
        foreach ($properties as $key => $property) {
            if (!empty($property['secret'])) {
                $this->assertContains(
                    $key,
                    self::SECRET_KEYS,
                    sprintf('Property "%s" is marked secret but is not in the documented credential list.', $key)
                );
            }
        }
    }

    /**
     * Forbidden namespaces must never reappear in the schema.
     *
     * The settings plan bars DB credentials/pool sizing from the admin surface
     * outright; `database.*` was exposed once and is explicitly listed as
     * DO-NOT-EXPOSE.
     */
    public function test_forbidden_key_namespaces_are_absent(): void
    {
        $forbiddenPrefixes = ['database.', 'jwt.', 'websocket.'];

        foreach (array_keys(self::properties()) as $key) {
            foreach ($forbiddenPrefixes as $prefix) {
                $this->assertStringStartsNotWith(
                    $prefix,
                    $key,
                    sprintf('Setting key "%s" is in a DO-NOT-EXPOSE namespace ("%s").', $key, $prefix)
                );
            }
        }
    }

    /**
     * @dataProvider constraintProvider
     * @param array<string, int|float> $constraints
     */
    public function test_constrained_property_carries_its_numeric_bounds(string $key, array $constraints): void
    {
        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);

        foreach ($constraints as $constraintKey => $expectedValue) {
            $this->assertArrayHasKey(
                $constraintKey,
                $properties[$key],
                sprintf('Property "%s" must declare "%s".', $key, $constraintKey)
            );
            $this->assertEqualsWithDelta(
                $expectedValue,
                $properties[$key][$constraintKey],
                0.0,
                sprintf('Property "%s" "%s" must equal the documented bound.', $key, $constraintKey)
            );
        }
    }

    /**
     * The five managed-worker switches are only HALF live, and must say so.
     *
     * Verified against phlix-server `start.php`: the spawn loop runs in the
     * MASTER before `Worker::runAll()` and reads `config/process.php` from disk
     * only, so it cannot see an admin override. The effective value is applied
     * in each worker's own `onWorkerStart`, which a graceful reload DOES re-run
     * — hence disabling works, and re-enabling works too, but ONLY while the
     * on-disk config still says `enabled => true` (no Worker group was ever
     * forked otherwise, and the in-app Restart button is SIGUSR2, a reload of
     * the already-executed master, not a fresh exec).
     *
     * The admin sees only `helpText`, so the asymmetry has to be disclosed
     * there — a dev-doc footnote does not reach the person flipping the switch.
     */
    public function test_managed_worker_switches_disclose_the_restart_asymmetry(): void
    {
        $properties = self::properties();

        $workerKeys = array_values(array_filter(
            array_keys($properties),
            static fn (string $key): bool => str_starts_with($key, 'process.')
                && str_ends_with($key, '.enabled')
        ));

        $this->assertCount(5, $workerKeys, 'Expected exactly the five managed-worker switches.');

        foreach ($workerKeys as $key) {
            $helpText = $properties[$key]['helpText'] ?? null;
            $this->assertIsString($helpText);

            // Turning a worker OFF is honoured on the next restart/reload.
            $this->assertMatchesRegularExpression(
                '/Turning it OFF takes effect after a restart/i',
                $helpText,
                sprintf('Property "%s" must tell the admin that disabling applies after a restart.', $key)
            );

            // Turning one back ON can require a FULL service restart, because the
            // master never forked a Worker group for a file-disabled entry.
            $this->assertMatchesRegularExpression(
                '/restarting from this page is not enough and the service itself has to be restarted/i',
                $helpText,
                sprintf(
                    'Property "%s" must warn that re-enabling a worker disabled in the on-disk config '
                    . 'needs a full service restart, not the in-app restart button.',
                    $key
                )
            );

            // The cost of the "idle process" design must not be hidden either.
            $this->assertMatchesRegularExpression(
                '/still occupies an idle process/i',
                $helpText,
                sprintf('Property "%s" must disclose that a disabled worker still holds a process.', $key)
            );

            // The pre-fix text claimed the worker "is not spawned". It IS spawned;
            // it starts, logs, and idles without arming its poll timer.
            $this->assertStringNotContainsString(
                'is not spawned',
                $helpText,
                sprintf(
                    'Property "%s" must not claim the worker "is not spawned" — start.php spawns it '
                    . 'regardless and the gate lives in onWorkerStart.',
                    $key
                )
            );
        }
    }

    /**
     * Liveness check for every documentation link in the schema.
     *
     * Excluded from the default suite (see `phpunit.xml`) because it performs
     * real network I/O; run it deliberately with `--group network`.
     *
     * @group network
     */
    public function test_help_links_resolve(): void
    {
        $urls = self::collectHelpLinkUrls(self::properties());
        $this->assertNotEmpty($urls);

        $dead = [];
        foreach ($urls as $url) {
            $status = SchemaLinkProbe::status($url);
            if (!SchemaLinkProbe::isAcceptable($status)) {
                $dead[] = sprintf('%s -> %d', $url, $status);
            }
        }

        $this->assertSame([], $dead, "Dead helpLinks URLs:\n" . implode("\n", $dead));
    }
}
