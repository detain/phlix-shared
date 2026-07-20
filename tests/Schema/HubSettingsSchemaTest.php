<?php

/**
 * Hub Settings Schema Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Schema;

use Phlix\Shared\Schema\SchemaPaths;
use PHPUnit\Framework\TestCase;

final class HubSettingsSchemaTest extends TestCase
{
    use SettingsSchemaAssertions;

    /**
     * Decoded hub-settings schema document.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $raw = (string) file_get_contents(SchemaPaths::hubSettings());
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'hub-settings.schema.json must decode to a JSON object.');

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
     * The expected property keys mapped to their JSON-Schema type.
     *
     * This list must stay in lockstep with
     * `Phlix\Hub\Hub\HubSettingsRepository::ALLOWED_KEYS`, which is what the
     * hub settings controller enumerates: the schema supplies only the render
     * metadata for those keys, so a schema key with no allow-list entry is
     * never rendered, and an allow-list key with no schema entry renders with
     * no label or help at all.
     *
     * The key IS the dotted config path — `auth.access_ttl` resolves
     * `phlix-hub/config/auth.php`'s `access_ttl`. The `*_token_ttl` spellings
     * this schema previously carried match no config path.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function propertyProvider(): array
    {
        return [
            // config/server.php
            'server.enrollment_ttl' => ['server.enrollment_ttl', 'integer'],
            // config/auth.php — NOT `access_token_ttl` / `refresh_token_ttl`
            'auth.access_ttl' => ['auth.access_ttl', 'integer'],
            'auth.refresh_ttl' => ['auth.refresh_ttl', 'integer'],
        ];
    }

    /**
     * Config paths the settings plan forbids the hub from ever exposing.
     *
     * Mirrors `HubSettingsRepository::DENIED_KEYS`: secrets, relay TLS
     * material, the hub domain values baked into already-issued enrollment
     * JWTs, ACME/TLS provisioning toggles, and listen/worker infrastructure.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'auth.secret',
        'server.hub_base_url',
        'server.public_domain',
        'server.domain',
        'server.tls_enabled',
        'server.subdomain_auto_claim',
        'server.relay_tls_cert',
        'server.relay_tls_key',
        'server.host',
        'server.port',
        'server.workers',
        'server.arr.sonarr.api_key',
        'server.arr.radarr.api_key',
    ];

    /**
     * Numeric constraints (minimum/maximum) the constrained keys must carry.
     *
     * @return array<string, array{0: string, 1: array<string, int|float>}>
     */
    public static function constraintProvider(): array
    {
        return [
            'server.enrollment_ttl' => ['server.enrollment_ttl', ['minimum' => 60, 'maximum' => 2592000]],
            'auth.access_ttl' => ['auth.access_ttl', ['minimum' => 300, 'maximum' => 86400]],
            'auth.refresh_ttl' => ['auth.refresh_ttl', ['minimum' => 3600, 'maximum' => 2592000]],
        ];
    }

    public function test_schema_declares_the_expected_meta_header(): void
    {
        $schema = self::schema();
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null);
        $this->assertSame('https://phlix.tv/schemas/hub-settings.schema.json', $schema['$id'] ?? null);
        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertFalse($schema['additionalProperties'] ?? null);

        $description = $schema['description'] ?? null;
        $this->assertIsString($description);
        $this->assertNotSame('', $description);
        // Regression guard for a copy-pasted description that described the
        // SERVER's responsibilities (library scanning) and had a missing space.
        $this->assertStringNotContainsString('handlesarr', $description);
        $this->assertStringNotContainsString('library scanning, and other background tasks', $description);
    }

    public function test_schema_properties_is_an_object(): void
    {
        $schema = self::schema();
        $this->assertArrayHasKey('properties', $schema);
        $this->assertIsArray($schema['properties'], 'properties must be a JSON object.');
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

        $this->assertSame($expected, $actual, 'hub-settings schema must declare exactly the expected settings keys.');
        $this->assertCount(3, $actual);
    }

    public function test_forbidden_infrastructure_keys_are_absent(): void
    {
        $properties = self::properties();

        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $properties,
                sprintf('Hub setting "%s" is a DO-NOT-EXPOSE key and must not appear in the schema.', $forbidden)
            );
        }
    }

    public function test_every_key_first_segment_is_a_flat_config_file_name(): void
    {
        foreach (array_keys(self::properties()) as $key) {
            $this->assertStringContainsString('.', $key, sprintf('Setting key "%s" must be dotted.', $key));

            $segments = explode('.', $key);

            $this->assertMatchesRegularExpression(
                '/^[A-Za-z0-9_-]+$/',
                $segments[0],
                sprintf('Setting key "%s" must start with a flat config file name.', $key)
            );

            foreach (array_slice($segments, 1) as $segment) {
                $this->assertNotSame('', $segment, sprintf('Setting key "%s" must not contain an empty path segment.', $key));
            }
        }
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
    public function test_property_has_label_and_help_text(string $key, string $expectedType): void
    {
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
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);

        $this->assertHelpLinksAreWellFormed($key, $properties[$key]);
        $this->assertFlagKeywordsAreBooleans($key, $properties[$key]);
    }

    /**
     * Every hub key names at least one technical concept, so every hub key
     * must carry at least one documentation link (plan §3.5).
     *
     * @dataProvider propertyProvider
     */
    public function test_property_carries_at_least_one_help_link(string $key, string $expectedType): void
    {
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);

        $links = $properties[$key]['helpLinks'] ?? null;
        $this->assertIsArray($links, sprintf('Property "%s" must declare helpLinks.', $key));
        $this->assertNotEmpty($links, sprintf('Property "%s" must declare at least one help link.', $key));
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
     * Liveness check for every documentation link in the hub schema.
     *
     * Excluded from the default suite (see `phpunit.xml`); run deliberately
     * with `--group network`.
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
