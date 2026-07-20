<?php

/**
 * Schema Paths Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
        // The enum must mirror the `media_items.type` column ENUM (server
        // migrations 001 -> 011 -> 034) member-for-member. It previously listed
        // only 6 of the 13 members plus a non-existent `image`, which let the
        // server's shaper relabel real photo/book/audiobook rows as `movie`.
        $this->assertSame(
            [
                'movie', 'series', 'season', 'episode', 'track', 'music', 'album',
                'artist', 'video', 'audio', 'book', 'photo', 'audiobook',
            ],
            $typeEnum,
            'media-item type enum must mirror the media_items.type column ENUM exactly.'
        );
        $this->assertNotContains(
            'image',
            $typeEnum,
            "'image' is a scanner-side label, not a media_items.type member — the column calls it 'photo'."
        );

        // The hierarchy/ordering fields the series detail page relies on.
        foreach (['parent_id', 'season_number', 'episode_number', 'episode_title'] as $field) {
            $this->assertArrayHasKey($field, $properties, "media-item schema must declare '{$field}'.");
        }
    }

    public function test_media_item_schema_rating_enum_covers_film_and_tv_scales(): void
    {
        $schema = json_decode((string) file_get_contents(SchemaPaths::mediaItem()), true);
        $this->assertIsArray($schema);

        $ratingEnum = $schema['properties']['rating']['enum'] ?? [];
        $this->assertIsArray($ratingEnum);

        // Phase C: MPAA film scale PLUS the US TV Parental Guidelines scale.
        // `NR` is normalized to `UNRATED` server-side and must NOT appear.
        $expected = [
            'G', 'PG', 'PG-13', 'R', 'NC-17', 'X', 'UNRATED',
            'TV-Y', 'TV-Y7', 'TV-G', 'TV-PG', 'TV-14', 'TV-MA',
        ];
        foreach ($expected as $rating) {
            $this->assertContains($rating, $ratingEnum, "media-item rating enum must include '{$rating}'.");
        }
        $this->assertContains(null, $ratingEnum, 'media-item rating enum must allow null.');
        $this->assertNotContains('NR', $ratingEnum, "media-item rating enum must NOT include 'NR' (normalized to UNRATED).");
    }

    public function test_media_item_schema_declares_phase_c_metadata_fields(): void
    {
        $schema = json_decode((string) file_get_contents(SchemaPaths::mediaItem()), true);
        $this->assertIsArray($schema);

        $properties = $schema['properties'] ?? [];
        $this->assertIsArray($properties);

        // Phase C detail-only additive fields (trailer/logo/still). All optional
        // and nullable, so existing consumers are unaffected.
        foreach (['trailer_url', 'trailer_key', 'trailer_site', 'logo_url', 'still_url'] as $field) {
            $this->assertArrayHasKey($field, $properties, "media-item schema must declare '{$field}'.");
            $type = $properties[$field]['type'] ?? null;
            $this->assertIsArray($type, "'{$field}' type must be a union.");
            $this->assertContains('null', $type, "'{$field}' must be nullable.");
        }

        // Additive-only: none of the new fields may be in `required`.
        $required = $schema['required'] ?? [];
        $this->assertIsArray($required);
        foreach (['trailer_url', 'trailer_key', 'trailer_site', 'logo_url', 'still_url'] as $field) {
            $this->assertNotContains($field, $required, "'{$field}' must stay optional (not required).");
        }
    }

    public function test_library_query_schema_ratings_filter_covers_film_and_tv_scales(): void
    {
        $schema = json_decode((string) file_get_contents(SchemaPaths::libraryQuery()), true);
        $this->assertIsArray($schema);

        $ratingsEnum = $schema['properties']['ratings']['items']['enum'] ?? [];
        $this->assertIsArray($ratingsEnum);
        foreach (['G', 'PG', 'PG-13', 'R', 'NC-17', 'X', 'UNRATED', 'TV-Y', 'TV-Y7', 'TV-G', 'TV-PG', 'TV-14', 'TV-MA'] as $rating) {
            $this->assertContains($rating, $ratingsEnum, "library-query ratings filter must include '{$rating}'.");
        }
        $this->assertNotContains('NR', $ratingsEnum, "library-query ratings filter must NOT include 'NR'.");
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
