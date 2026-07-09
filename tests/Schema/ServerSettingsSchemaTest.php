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

/**
 * @covers \Phlix\Shared\Schema\SchemaPaths
 */
final class ServerSettingsSchemaTest extends TestCase
{
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
     * @return array<string, array{0: string, 1: string}>
     */
    public static function propertyProvider(): array
    {
        return [
            'hwaccel.enabled' => ['hwaccel.enabled', 'boolean'],
            'hwaccel.prefer_hardware' => ['hwaccel.prefer_hardware', 'boolean'],
            'hwaccel.probe_timeout' => ['hwaccel.probe_timeout', 'integer'],
            'tmdb.api_key' => ['tmdb.api_key', 'string'],
            'auth.signup_mode' => ['auth.signup_mode', 'string'],
            'marker_detection.similarity_threshold' => ['marker_detection.similarity_threshold', 'number'],
            'marker_detection.intro_max_duration' => ['marker_detection.intro_max_duration', 'integer'],
            'subtitles.enabled' => ['subtitles.enabled', 'boolean'],
            'subtitles.default_language' => ['subtitles.default_language', 'string'],
            'subtitles.burn_in_by_default' => ['subtitles.burn_in_by_default', 'boolean'],
            'discovery.discovery_port' => ['discovery.discovery_port', 'integer'],
            'trickplay.enabled' => ['trickplay.enabled', 'boolean'],
            'trickplay.interval_seconds' => ['trickplay.interval_seconds', 'integer'],
            'newsletter.enabled' => ['newsletter.enabled', 'boolean'],
            'newsletter.send_hour' => ['newsletter.send_hour', 'integer'],
            'port-forward.port_forwarding.upnp_enabled' => ['port-forward.port_forwarding.upnp_enabled', 'boolean'],
            'trakt.client_id' => ['trakt.client_id', 'string'],
            'trakt.client_secret' => ['trakt.client_secret', 'string'],
            'trakt.redirect_uri' => ['trakt.redirect_uri', 'string'],
            'matching.noise_suffixes' => ['matching.noise_suffixes', 'array'],
            'metadata.provider_priority' => ['metadata.provider_priority', 'object'],
            'metadata.genres_mode' => ['metadata.genres_mode', 'string'],
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
        $this->assertCount(22, $actual);
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
}
