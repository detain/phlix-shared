<?php

/**
 * SettingsCodec Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Settings;

use JsonException;
use Phlix\Shared\Settings\SettingsCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Characterization of the settings-column codec, lifted from the two private
 * repo twins (encode/decode arms are byte-identical on disk except the one
 * documented divergence: this kernel ships the hub's JSON_THROW_ON_ERROR
 * posture, the server's silent empty-string variant is what dies here).
 */
#[CoversClass(\Phlix\Shared\Settings\SettingsCodec::class)]
final class SettingsCodecTest extends TestCase
{
    public function testEncodeBoolIsTheOneZeroVocabulary(): void
    {
        self::assertSame('1', SettingsCodec::encode(true, 'bool'));
        self::assertSame('0', SettingsCodec::encode(false, 'bool'));
        // Truthiness of the loose value — both twins cast, neither validates.
        self::assertSame('1', SettingsCodec::encode('yes', 'bool'));
        self::assertSame('0', SettingsCodec::encode('', 'bool'));
    }

    /**
     * @return array<string, array{mixed, string}> [value, expected]
     */
    public static function intEncodings(): array
    {
        return [
            'int' => [7, '7'],
            'numeric string' => ['7', '7'],
            'float-looking string truncates (is_numeric then (int))' => ['7.9', '7'],
            'float truncates' => [7.9, '7'],
            'non-numeric reads 0' => ['abc', '0'],
            'null reads 0 (not numeric)' => [null, '0'],
            'empty string reads 0' => ['', '0'],
            'true reads 0 (bool is not numeric)' => [true, '0'],
        ];
    }

    #[DataProvider('intEncodings')]
    public function testEncodeIntCoercionLaw(mixed $value, string $expected): void
    {
        self::assertSame($expected, SettingsCodec::encode($value, 'int'));
    }

    public function testEncodeFloatArms(): void
    {
        self::assertSame('2.5', SettingsCodec::encode(2.5, 'float'));
        self::assertSame('2.5', SettingsCodec::encode('2.5', 'float'));
        self::assertSame('0', SettingsCodec::encode('junk', 'float'));
        self::assertSame('7', SettingsCodec::encode(7, 'float'));
    }

    public function testEncodeJsonIsCompactAndRoundTrips(): void
    {
        self::assertSame('{"a":1,"b":[2,3]}', SettingsCodec::encode(['a' => 1, 'b' => [2, 3]], 'json'));
        self::assertSame(['a' => 1, 'b' => [2, 3]], SettingsCodec::decode('{"a":1,"b":[2,3]}', 'json'));
    }

    /**
     * The hub stricter posture this kernel ships: an unencodable value RAISES
     * and is never stored (the server's silent `(string) json_encode` variant
     * persisted '' — the drift this leg ends; disclosed in the CHANGELOG).
     */
    public function testEncodeJsonRefusesUnencodablePayloads(): void
    {
        $this->expectException(JsonException::class);
        SettingsCodec::encode(NAN, 'json');
    }

    public function testEncodeDefaultArmNonScalarRefusesUnencodablePayloads(): void
    {
        $this->expectException(JsonException::class);
        SettingsCodec::encode(['k' => NAN], 'string');
    }

    public function testEncodeDefaultArms(): void
    {
        self::assertSame('hello', SettingsCodec::encode('hello', 'string'));
        self::assertSame('42x', SettingsCodec::encode('42x', 'weird-type'));
        // Scalar fall-throughs cast; null is NOT scalar so it jsons to 'null'.
        self::assertSame('1', SettingsCodec::encode(true, 'string'));
        self::assertSame('null', SettingsCodec::encode(null, 'string'));
    }

    public function testDecodeBoolGrammar(): void
    {
        self::assertTrue(SettingsCodec::decode('1', 'bool'));
        self::assertFalse(SettingsCodec::decode('0', 'bool'));
        // Legacy-row tolerance leg (strtolower === 'true').
        self::assertTrue(SettingsCodec::decode('true', 'bool'));
        self::assertTrue(SettingsCodec::decode('TRUE', 'bool'));
        // Corrupt row decodes FALSE silently — correct for storage: a bad
        // column value must not flip a setting (HUB-ENVBOOL law: env-knob
        // grammar lives elsewhere, never here).
        self::assertFalse(SettingsCodec::decode('garbage', 'bool'));
        self::assertFalse(SettingsCodec::decode('', 'bool'));
    }

    public function testDecodeNumericAndJsonArms(): void
    {
        self::assertSame(42, SettingsCodec::decode('42', 'int'));
        self::assertSame(42, SettingsCodec::decode('42x', 'int'));
        self::assertSame(0, SettingsCodec::decode('', 'int'));
        self::assertSame(2.5, SettingsCodec::decode('2.5', 'float'));
        self::assertSame(0.0, SettingsCodec::decode('junk', 'float'));
        self::assertSame(['a' => 1], SettingsCodec::decode('{"a":1}', 'json'));
        self::assertNull(SettingsCodec::decode('not json', 'json'));
        self::assertSame('raw text', SettingsCodec::decode('raw text', 'string'));
    }

    /**
     * @return array<string, array{mixed, string}> [value, valueType]
     */
    public static function roundTrips(): array
    {
        return [
            'bool on' => [true, 'bool'],
            'bool off' => [false, 'bool'],
            'int' => [7, 'int'],
            'float' => [2.5, 'float'],
            'json' => [['a' => 1, 'b' => [2, 3]], 'json'],
            'string' => ['plain', 'string'],
        ];
    }

    #[DataProvider('roundTrips')]
    public function testEncodeDecodeRoundTrips(mixed $value, string $valueType): void
    {
        self::assertSame($value, SettingsCodec::decode(SettingsCodec::encode($value, $valueType), $valueType));
    }

    public function testEffectiveLetsAnyNonNullOverrideWin(): void
    {
        self::assertSame(7, SettingsCodec::effective(7, 'default'));
        self::assertSame('default', SettingsCodec::effective(null, 'default'));
        // The law's teeth: falsy-but-present values are REAL settings.
        self::assertFalse(SettingsCodec::effective(false, 'default'));
        self::assertSame(0, SettingsCodec::effective(0, 'default'));
        self::assertSame('', SettingsCodec::effective('', 'default'));
        // A null default is a legal default.
        self::assertNull(SettingsCodec::effective(null, null));
    }

    public function testClampValueBoundsAreInclusive(): void
    {
        self::assertSame(5, SettingsCodec::clampValue(5, 1, 10));
        self::assertSame(1, SettingsCodec::clampValue(0, 1, 10));
        self::assertSame(10, SettingsCodec::clampValue(11, 1, 10));
        self::assertSame(1, SettingsCodec::clampValue(1, 1, 10));
        self::assertSame(10, SettingsCodec::clampValue(10, 1, 10));
        self::assertSame(3650, SettingsCodec::clampValue(99999, 1, 3650));
    }

    public function testClampValueOnTheFloatDomain(): void
    {
        self::assertSame(2.5, SettingsCodec::clampValue(2.5, 1, 3));
        // Below the floor: the (int) bound answers, not a cast of the input.
        self::assertSame(1, SettingsCodec::clampValue(0.5, 1, 3));
        self::assertSame(3, SettingsCodec::clampValue(9.5, 1, 3));
    }

    public function testTheClassIsFinalAndUninstantiable(): void
    {
        $reflection = new ReflectionClass(SettingsCodec::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($reflection->isFinal());
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }
}
