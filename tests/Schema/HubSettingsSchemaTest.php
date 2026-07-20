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
     * @return array<string, array{0: string, 1: string}>
     */
    public static function propertyProvider(): array
    {
        return [
            'server.enrollment_ttl' => ['server.enrollment_ttl', 'integer'],
            'server.relay_ping_interval' => ['server.relay_ping_interval', 'integer'],
            'server.max_servers_per_user' => ['server.max_servers_per_user', 'integer'],
            'server.heartbeat_interval' => ['server.heartbeat_interval', 'integer'],
            'server.enrollment_renewal_threshold' => ['server.enrollment_renewal_threshold', 'integer'],
            'server.subdomain_auto_claim' => ['server.subdomain_auto_claim', 'boolean'],
            'server.tls_enabled' => ['server.tls_enabled', 'boolean'],
            'server.domain' => ['server.domain', 'string'],
            'auth.access_token_ttl' => ['auth.access_token_ttl', 'integer'],
            'auth.refresh_token_ttl' => ['auth.refresh_token_ttl', 'integer'],
            'logger.level' => ['logger.level', 'string'],
            'logger.audit_enabled' => ['logger.audit_enabled', 'boolean'],
        ];
    }

    /**
     * Numeric constraints (minimum/maximum) the constrained keys must carry.
     *
     * @return array<string, array{0: string, 1: array<string, int|float>}>
     */
    public static function constraintProvider(): array
    {
        return [
            'server.enrollment_ttl' => ['server.enrollment_ttl', ['minimum' => 60, 'maximum' => 2592000]],
            'server.relay_ping_interval' => ['server.relay_ping_interval', ['minimum' => 5, 'maximum' => 300]],
            'server.max_servers_per_user' => ['server.max_servers_per_user', ['minimum' => 0, 'maximum' => 1000]],
            'server.heartbeat_interval' => ['server.heartbeat_interval', ['minimum' => 10, 'maximum' => 600]],
            'server.enrollment_renewal_threshold' => ['server.enrollment_renewal_threshold', ['minimum' => 60, 'maximum' => 604800]],
            'auth.access_token_ttl' => ['auth.access_token_ttl', ['minimum' => 300, 'maximum' => 86400]],
            'auth.refresh_token_ttl' => ['auth.refresh_token_ttl', ['minimum' => 3600, 'maximum' => 2592000]],
        ];
    }

    public function test_schema_declares_the_expected_meta_header(): void
    {
        $schema = self::schema();
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null);
        $this->assertSame('https://phlix.tv/schemas/hub-settings.schema.json', $schema['$id'] ?? null);
        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertFalse($schema['additionalProperties'] ?? null);
    }

    public function test_schema_properties_is_an_object(): void
    {
        $schema = self::schema();
        $this->assertArrayHasKey('properties', $schema);
        $this->assertIsArray($schema['properties'], 'properties must be a JSON object.');
    }

    public function test_schema_all_properties_carry_required_keywords(): void
    {
        $schema = self::schema();
        $this->assertArrayHasKey('properties', $schema);
        $this->assertIsArray($schema['properties']);

        foreach ($schema['properties'] as $key => $property) {
            $this->assertIsString($key);
            $this->assertIsArray($property, sprintf('Property "%s" must be a JSON object.', $key));

            $group = $property['group'] ?? null;
            $description = $property['description'] ?? null;
            $label = $property['label'] ?? null;
            $helpText = $property['helpText'] ?? null;

            $this->assertIsString($group, sprintf('Property "%s" must have a string group.', $key));
            $this->assertNotSame('', $group, sprintf('Property "%s" group must be non-empty.', $key));

            $this->assertIsString($description, sprintf('Property "%s" must have a string description.', $key));
            $this->assertNotSame('', $description, sprintf('Property "%s" description must be non-empty.', $key));

            $this->assertIsString($label, sprintf('Property "%s" must have a string label.', $key));
            $this->assertNotSame('', $label, sprintf('Property "%s" label must be non-empty.', $key));

            $this->assertIsString($helpText, sprintf('Property "%s" must have a string helpText.', $key));
            $this->assertNotSame('', $helpText, sprintf('Property "%s" helpText must be non-empty.', $key));

            // helpLinks — if present, each entry must be {text: non-empty-string, url: https://...}
            if (isset($property['helpLinks']) && is_array($property['helpLinks'])) {
                foreach ($property['helpLinks'] as $index => $link) {
                    $this->assertIsArray($link, sprintf('Property "%s" helpLinks[%d] must be an object.', $key, $index));
                    $this->assertArrayHasKey('text', $link);
                    $this->assertArrayHasKey('url', $link);
                    $this->assertIsString($link['text']);
                    $this->assertNotSame('', $link['text'], sprintf('Property "%s" helpLinks[%d].text must be non-empty.', $key, $index));
                    $this->assertIsString($link['url']);
                    $this->assertStringStartsWith('https://', $link['url'], sprintf('Property "%s" helpLinks[%d].url must start with https://.', $key, $index));
                }
            }

            // tier — if present, must be "standard" or "advanced"
            if (array_key_exists('tier', $property)) {
                $this->assertContains(
                    $property['tier'],
                    ['standard', 'advanced'],
                    sprintf('Property "%s" tier must be "standard" or "advanced".', $key)
                );
            }
        }
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
        $this->assertCount(12, $actual);
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

        $label = $properties[$key]['label'] ?? null;
        $helpText = $properties[$key]['helpText'] ?? null;

        $this->assertIsString($label, sprintf('Property "%s" must have a string label.', $key));
        $this->assertNotSame('', $label, sprintf('Property "%s" label must be non-empty.', $key));

        $this->assertIsString($helpText, sprintf('Property "%s" must have a string helpText.', $key));
        $this->assertNotSame('', $helpText, sprintf('Property "%s" helpText must be non-empty.', $key));
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
