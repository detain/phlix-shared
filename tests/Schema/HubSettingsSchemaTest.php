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
}
