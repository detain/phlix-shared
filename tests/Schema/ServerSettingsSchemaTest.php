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
            'hwaccel.probe_timeout' => ['hwaccel.probe_timeout', 'integer'],
            // config/transcoding.php
            'transcoding.preferred_accelerator' => ['transcoding.preferred_accelerator', 'string'],
            'transcoding.include_software_fallback' => ['transcoding.include_software_fallback', 'boolean'],
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
            // config/marker_detection.php
            'marker_detection.similarity_threshold' => ['marker_detection.similarity_threshold', 'number'],
            'marker_detection.intro_max_duration' => ['marker_detection.intro_max_duration', 'integer'],
            // config/subtitles.php
            'subtitles.enabled' => ['subtitles.enabled', 'boolean'],
            'subtitles.default_language' => ['subtitles.default_language', 'string'],
            'subtitles.burn_in_by_default' => ['subtitles.burn_in_by_default', 'boolean'],
            // config/discovery.php, config/trickplay.php, config/newsletter.php
            'discovery.discovery_port' => ['discovery.discovery_port', 'integer'],
            'trickplay.enabled' => ['trickplay.enabled', 'boolean'],
            'trickplay.interval_seconds' => ['trickplay.interval_seconds', 'integer'],
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
            'hwaccel.probe_timeout' => ['hwaccel.probe_timeout', ['minimum' => 0]],
            'marker_detection.similarity_threshold' => [
                'marker_detection.similarity_threshold',
                ['minimum' => 0, 'maximum' => 1],
            ],
            'marker_detection.intro_max_duration' => ['marker_detection.intro_max_duration', ['minimum' => 0]],
            'discovery.discovery_port' => ['discovery.discovery_port', ['minimum' => 1, 'maximum' => 65535]],
            'trickplay.interval_seconds' => ['trickplay.interval_seconds', ['minimum' => 1]],
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
        $this->assertCount(43, $actual);
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
