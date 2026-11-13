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
use Phlix\Shared\Plugin\ManifestValidationError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(\Phlix\Shared\Plugin\Manifest::class)]
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

    /**
     * LW-060: the `settings` property docblock promises `type: string` per
     * entry, so fromArray() must enforce exactly that at the parse boundary.
     * An entry whose `type` is missing or not a string is malformed and is
     * dropped from the typed view (the raw payload stays in rawData for the
     * consuming validator's report) — it must not be admitted carrying the
     * shape promise as an unverified assertion.
     */
    public function test_fromArray_ignores_settings_entries_without_string_type(): void
    {
        $manifest = Manifest::fromArray([
            'settings' => [
                'ok' => ['type' => 'string'],
                'missing_type' => ['label' => 'no type key at all'],
                'int_type' => ['type' => 42],
                'null_type' => ['type' => null],
                'array_type' => ['type' => ['nested']],
            ],
        ]);
        $this->assertSame(['ok' => ['type' => 'string']], $manifest->settings);
    }

    /**
     * SHR-S5: PHP folds a decimal-integer-looking key ("42") to int 42, so
     * array_keys() hands fromArray() an int for it. getUnknownFields()
     * promises `list<string>`, and the consuming validator feeds each entry
     * into the strict-typed `string $field` of ManifestValidationError — an
     * int there is a TypeError. The cast must be wire-identical: the string
     * form is exactly the key as written in the JSON, order preserved, and
     * non-canonical integer spellings ("08", "-0", "1.5") stay as written.
     */
    public function test_getUnknownFields_returns_integer_like_keys_as_strings(): void
    {
        $manifest = Manifest::fromJson(
            '{"name":"phlix-plugin-x","42":1,"extra":true,"-7":null,"08":0,"-0":0,"1.5":0}'
        );

        $unknown = $manifest->getUnknownFields();
        $this->assertSame(['42', 'extra', '-7', '08', '-0', '1.5'], $unknown);

        $fields = [];
        foreach ($unknown as $key) {
            $fields[] = (new ManifestValidationError($key, 'unknown_field', 'x'))->toArray()['field'];
        }
        $this->assertSame(
            '["42","extra","-7","08","-0","1.5"]',
            json_encode($fields, JSON_THROW_ON_ERROR),
            'the unknown-field keys must serialise as JSON strings, exactly as written'
        );
    }

    /**
     * SHR-S5: the recognised top-level keys are the bundled schema's own
     * `properties` (schemas/manifest.schema.json is the canonical schema;
     * phlix-server's ManifestSchema validates against it), so the
     * unknown-field report can never disagree with the schema.
     */
    public function test_known_top_level_keys_match_the_bundled_schema(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/schemas/manifest.schema.json');
        $this->assertIsString($source);
        $schema = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($schema);
        $this->assertIsArray($schema['properties'] ?? null);

        $this->assertSame(array_keys($schema['properties']), Manifest::KNOWN_TOP_LEVEL_KEYS);
    }
}
