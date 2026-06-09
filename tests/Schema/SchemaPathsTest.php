<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Schema;

use Phlix\Shared\Schema\SchemaPaths;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \Phlix\Shared\Schema\SchemaPaths
 */
final class SchemaPathsTest extends TestCase
{
    public function test_dir_points_at_the_bundled_schemas_directory(): void
    {
        $dir = SchemaPaths::dir();
        $this->assertStringEndsWith('/schemas', $dir);
        $this->assertDirectoryExists($dir, 'SchemaPaths::dir() must resolve to the real schemas directory.');
    }

    public function test_server_settings_path_has_the_right_filename(): void
    {
        $this->assertStringEndsWith('/schemas/server-settings.schema.json', SchemaPaths::serverSettings());
    }

    public function test_webhook_events_path_has_the_right_filename(): void
    {
        $this->assertStringEndsWith('/schemas/webhook-events.json', SchemaPaths::webhookEvents());
    }

    public function test_server_settings_path_points_at_an_existing_valid_json_file(): void
    {
        $path = SchemaPaths::serverSettings();
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'server-settings.schema.json must decode to a JSON object.');
    }

    public function test_webhook_events_path_points_at_an_existing_valid_json_file(): void
    {
        $path = SchemaPaths::webhookEvents();
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'webhook-events.json must decode to a JSON object.');
    }

    public function test_media_item_path_has_the_right_filename(): void
    {
        $this->assertStringEndsWith('/schemas/media-item.schema.json', SchemaPaths::mediaItem());
    }

    public function test_library_query_path_has_the_right_filename(): void
    {
        $this->assertStringEndsWith('/schemas/library-query.schema.json', SchemaPaths::libraryQuery());
    }

    public function test_media_item_schema_declares_the_series_hierarchy(): void
    {
        $path = SchemaPaths::mediaItem();
        $this->assertFileExists($path);

        $schema = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($schema, 'media-item.schema.json must decode to a JSON object.');

        $properties = $schema['properties'] ?? [];
        $this->assertIsArray($properties);

        // The `season` type discriminator must exist alongside series/episode so
        // the contract can carry the full series→season→episode hierarchy.
        $typeProp = $properties['type'] ?? [];
        $this->assertIsArray($typeProp);
        $typeEnum = $typeProp['enum'] ?? [];
        $this->assertIsArray($typeEnum);
        foreach (['movie', 'series', 'season', 'episode', 'audio', 'image'] as $expected) {
            $this->assertContains($expected, $typeEnum, "media-item type enum must include '{$expected}'.");
        }

        // The hierarchy/ordering fields the series detail page relies on.
        foreach (['parent_id', 'season_number', 'episode_number', 'episode_title'] as $field) {
            $this->assertArrayHasKey($field, $properties, "media-item schema must declare '{$field}'.");
        }
    }

    public function test_library_query_schema_declares_the_hierarchy_scope_params(): void
    {
        $path = SchemaPaths::libraryQuery();
        $this->assertFileExists($path);

        $schema = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($schema, 'library-query.schema.json must decode to a JSON object.');

        $properties = $schema['properties'] ?? [];
        $this->assertIsArray($properties);
        foreach (['libraryId', 'parentId', 'topLevel'] as $param) {
            $this->assertArrayHasKey($param, $properties, "library-query schema must declare '{$param}'.");
        }
    }

    public function test_constructor_is_private(): void
    {
        $reflection = new ReflectionClass(SchemaPaths::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor, 'SchemaPaths must declare a constructor.');
        $this->assertTrue(
            $constructor->isPrivate(),
            'SchemaPaths::__construct must be private to prevent instantiation.'
        );

        // Exercise the constructor through reflection so static-analysis
        // coverage reflects the (intentionally inert) body. Bypasses the
        // `private` modifier so the test still runs on stricter PHP runtimes.
        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor->setAccessible(true);
        $constructor->invoke($instance);
        $this->assertInstanceOf(SchemaPaths::class, $instance);
    }
}
