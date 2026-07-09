<?php

/**
 * Manifest Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Plugin;

use Phlix\Shared\Plugin\Manifest;
use Phlix\Shared\Plugin\ManifestType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Phlix\Shared\Plugin\Manifest
 */
final class ManifestTest extends TestCase
{
    public function test_fromJson_throws_on_malformed_json(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Manifest is not valid JSON');
        Manifest::fromJson('{not valid');
    }

    public function test_fromJson_throws_on_non_object_root(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Manifest root must be a JSON object');
        Manifest::fromJson('"a string"');
    }

    public function test_fromArray_populates_readonly_props(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-example',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Acme\\Plugin\\Entry',
            'events' => ['phlix.playback.started', 'phlix.user.created'],
            'settings' => [
                'api_key' => ['type' => 'string', 'required' => true, 'secret' => true],
            ],
            'signature' => 'sha256:deadbeef',
        ]);

        $this->assertSame('phlix-plugin-example', $manifest->name);
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertSame('0.10.0', $manifest->phlixMinServerVersion);
        $this->assertSame('notifier', $manifest->type);
        $this->assertSame('Acme\\Plugin\\Entry', $manifest->entry);
        $this->assertSame(['phlix.playback.started', 'phlix.user.created'], $manifest->events);
        $this->assertSame(
            ['api_key' => ['type' => 'string', 'required' => true, 'secret' => true]],
            $manifest->settings,
        );
        $this->assertSame('sha256:deadbeef', $manifest->signature);
        $this->assertSame([], $manifest->getUnknownFields());
    }

    public function test_fromArray_records_unknown_fields(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-x',
            'extra' => 'unexpected',
            'another' => 42,
        ]);

        $this->assertSame(['extra', 'another'], $manifest->getUnknownFields());
    }

    public function test_fromArray_returns_null_signature_when_missing(): void
    {
        $manifest = Manifest::fromArray(['name' => 'p']);
        $this->assertNull($manifest->signature);
    }

    public function test_manifestType_resolves_enum(): void
    {
        $manifest = Manifest::fromArray(['type' => 'scrobbler']);
        $this->assertSame(ManifestType::Scrobbler, $manifest->manifestType());
    }

    public function test_manifestType_returns_null_for_unknown_value(): void
    {
        $manifest = Manifest::fromArray(['type' => 'not-a-type']);
        $this->assertNull($manifest->manifestType());
    }

    public function test_manifestType_returns_null_when_type_empty(): void
    {
        $manifest = Manifest::fromArray([]);
        $this->assertNull($manifest->manifestType());
    }

    public function test_toArray_round_trips_raw_data(): void
    {
        $data = [
            'name' => 'phlix-plugin-x',
            'version' => '0.1.0',
            'type' => 'notifier',
        ];

        $this->assertSame($data, Manifest::fromArray($data)->toArray());
    }

    public function test_getRawData_returns_input(): void
    {
        $data = ['name' => 'p', 'foo' => 'bar'];
        $this->assertSame($data, Manifest::fromArray($data)->getRawData());
    }

    public function test_fromArray_ignores_non_string_events(): void
    {
        $manifest = Manifest::fromArray([
            'events' => ['phlix.x', 42, true, 'phlix.y'],
        ]);
        $this->assertSame(['phlix.x', 'phlix.y'], $manifest->events);
    }

    public function test_fromArray_ignores_non_array_settings_entries(): void
    {
        $manifest = Manifest::fromArray([
            'settings' => [
                'k1' => ['type' => 'string'],
                'k2' => 'not-an-array',
                12 => ['type' => 'int'],
            ],
        ]);
        $this->assertSame(['k1' => ['type' => 'string']], $manifest->settings);
    }
}
