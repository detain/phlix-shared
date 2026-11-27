<?php

/**
 * SettingsCodec Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Settings;

use InvalidArgumentException;
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
 *
 * S-shared-19 strict arms: the 'json' decode leg RAISES JsonException on
 * malformed stored text (depth-capped) instead of answering a silent null,
 * and both directions reject a $valueType outside string|int|bool|float|json
 * with InvalidArgumentException. Every pinned OUTPUT of the five valid arms
 * is unchanged — the strictness only converts silent classes into throws.
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

    /**
     * The 'string' arm's byte-preserved cast law (the twins' old default
     * body): scalars cast, null JSON-encodes to the four letters 'null'.
     */
    public function testEncodeStringArmIsTheTwinsCastLaw(): void
    {
        self::assertSame('hello', SettingsCodec::encode('hello', 'string'));
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
        // grammar lives elsewhere, never here). The 'json' arm got the loud
        // treatment in S-shared-19; this documented silence stays.
        self::assertFalse(SettingsCodec::decode('garbage', 'bool'));
        self::assertFalse(SettingsCodec::decode('', 'bool'));
    }

    public function testDecodeNumericAndStringArms(): void
    {
        self::assertSame(42, SettingsCodec::decode('42', 'int'));
        self::assertSame(42, SettingsCodec::decode('42x', 'int'));
        self::assertSame(0, SettingsCodec::decode('', 'int'));
        self::assertSame(2.5, SettingsCodec::decode('2.5', 'float'));
        self::assertSame(0.0, SettingsCodec::decode('junk', 'float'));
        self::assertSame('raw text', SettingsCodec::decode('raw text', 'string'));
    }

    /**
     * @return array<string, array{string, mixed}> [raw, expected]
     */
    public static function wellFormedJsonDocuments(): array
    {
        return [
            'object' => ['{"a":1}', ['a' => 1]],
            'empty object' => ['{}', []],
            'list' => ['[1,2]', [1, 2]],
            'empty list' => ['[]', []],
            // The strict arm must not over-reach: the JSON literal null is
            // a well-formed document and still decodes to PHP null — the
            // S-shared-19 target was MALFORMED text, never legitimate null.
            'null literal' => ['null', null],
            'true literal' => ['true', true],
            'string literal' => ['"text"', 'text'],
            'number literal' => ['42', 42],
            'float literal' => ['3.5', 3.5],
        ];
    }

    #[DataProvider('wellFormedJsonDocuments')]
    public function testDecodeJsonArmAcceptsWellFormedDocuments(string $raw, mixed $expected): void
    {
        self::assertSame($expected, SettingsCodec::decode($raw, 'json'));
    }

    /**
     * @return array<string, array{string}> [raw]
     */
    public static function malformedJsonDocuments(): array
    {
        return [
            'plain prose' => ['not json'],
            'unclosed object' => ['{'],
            'dangling member' => ['{"a":}'],
            'trailing comma' => ['[1,]'],
            'truncated document' => ['{"a":1'],
            'bare quote' => ['"'],
            'invalid UTF-8' => ["\xFF\xFE"],
            'empty string (no document)' => [''],
        ];
    }

    /**
     * S-shared-19: corrupt stored text RAISES. The old bare json_decode
     * answered `null` with no signal — indistinguishable from a legitimately
     * stored null literal.
     */
    #[DataProvider('malformedJsonDocuments')]
    public function testDecodeJsonArmRaisesOnMalformedStoredText(string $raw): void
    {
        $this->expectException(JsonException::class);
        SettingsCodec::decode($raw, 'json');
    }

    /**
     * The depth ceiling's accepted side: 511 bracket levels need a depth
     * argument of 512 (PHP counts the document root in the budget) — the
     * ceiling admits exactly that, byte-for-byte.
     */
    public function testDecodeJsonAcceptsTheDeepestDocumentTheCeilingAllows(): void
    {
        $expected = 1;
        for ($level = 0; $level < 511; $level++) {
            $expected = [$expected];
        }

        $raw = str_repeat('[', 511) . '1' . str_repeat(']', 511);
        self::assertSame($expected, SettingsCodec::decode($raw, 'json'));
    }

    /**
     * The depth ceiling's rejected side: one level past the budget is a
     * JsonException, never a silently truncated structure.
     */
    public function testDecodeJsonRaisesOnDepthExhaustion(): void
    {
        $raw = str_repeat('[', 512) . '1' . str_repeat(']', 512);

        $this->expectException(JsonException::class);
        SettingsCodec::decode($raw, 'json');
    }

    /**
     * @return array<string, array{string}> [valueType]
     */
    public static function unknownValueTypes(): array
    {
        return [
            'empty' => [''],
            'legacy fall-through token' => ['weird-type'],
            'uppercase' => ['STRING'],
            'capitalised' => ['Int'],
            'raw schema type integer (server maps it BEFORE the codec)' => ['integer'],
            'raw schema type boolean' => ['boolean'],
            'raw schema type number' => ['number'],
            'raw schema type array' => ['array'],
            'raw schema type object' => ['object'],
            'padded token' => ['json '],
        ];
    }

    /**
     * S-shared-19: the encode direction no longer falls through on a type
     * outside the arms (the twins' silent default pass-through is ended).
     */
    #[DataProvider('unknownValueTypes')]
    public function testEncodeRejectsUnknownValueTypes(string $valueType): void
    {
        $this->expectException(InvalidArgumentException::class);
        SettingsCodec::encode('x', $valueType);
    }

    /**
     * S-shared-19: same gate on the decode direction — the old
     * `default => $raw` could hand a json-typed column back as prose.
     */
    #[DataProvider('unknownValueTypes')]
    public function testDecodeRejectsUnknownValueTypes(string $valueType): void
    {
        $this->expectException(InvalidArgumentException::class);
        SettingsCodec::decode('{"a":1}', $valueType);
    }

    /**
     * Fail-fast doctrine: the rejection names the offender AND the legal
     * vocabulary, so a misroute is diagnosable from the exception alone.
     */
    public function testRejectionCarriesTheOffenderAndTheVocabulary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/^Unknown settings valueType "nope"; the codec vocabulary is exactly string\|int\|bool\|float\|json\.$/',
        );

        SettingsCodec::decode('x', 'nope');
    }

    /**
     * Drift pin: the gate's accept-set is EXACTLY the five documented arms —
     * widening the vocabulary without widening the arms (or the docs) reddens
     * here before any consumer sees a silent fall-through again.
     */
    public function testTheVocabularyIsExactlyTheFiveDocumentedArms(): void
    {
        $types = (new ReflectionClass(SettingsCodec::class))->getConstant('VALUE_TYPES');

        self::assertSame(['string', 'int', 'bool', 'float', 'json'], $types);
    }

    /**
     * @return array<string, array{mixed, string, string}> [value, encoded, valueType]
     */
    public static function everyArmInBothDirections(): array
    {
        return [
            'string' => ['plain', 'plain', 'string'],
            'int' => [7, '7', 'int'],
            'float' => [2.5, '2.5', 'float'],
            'bool' => [true, '1', 'bool'],
            'json' => [['a' => 1], '{"a":1}', 'json'],
        ];
    }

    /**
     * Every vocabulary member reaches a real arm in BOTH directions with its
     * historical bytes — the strictness is additive, never a re-tune of what
     * valid traffic encodes to or decodes from.
     */
    #[DataProvider('everyArmInBothDirections')]
    public function testEachVocabularyTypeReachesItsArms(mixed $value, string $encoded, string $valueType): void
    {
        self::assertSame($encoded, SettingsCodec::encode($value, $valueType));
        self::assertSame($value, SettingsCodec::decode($encoded, $valueType));
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
