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
