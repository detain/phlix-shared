<?php

/**
 * Security Base64 Url Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Security;

use Phlix\Shared\Security\Base64Url;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Base64Url} — the canonical base64url codec family.
 *
 * The encode vectors are the independently published RFC 4648 §10 examples
 * (plus one byte triple chosen to carry `+` and `/` in standard base64), so
 * the encoder cannot satisfy this file by merely agreeing with itself. The
 * decode arms each carry a positive control beside every refusal, mirroring
 * the house posture: an arm that always returned `''` would pass a
 * refusal-only suite.
 *
 * @package Phlix\Shared\Tests\Security
 */
#[CoversClass(Base64Url::class)]
final class Base64UrlTest extends TestCase
{
    /**
     * RFC 4648 §10 canonical vectors: input bytes → unpadded base64url.
     *
     * @return array<string, array{string, string}>
     */
    public static function encodeVectors(): array
    {
        return [
            'empty'            => ['', ''],
            'f'                => ['f', 'Zg'],
            'fo'               => ['fo', 'Zm8'],
            'foo'              => ['foo', 'Zm9v'],
            'foob'             => ['foob', 'Zm9vYg'],
            'fooba'            => ['fooba', 'Zm9vYmE'],
            'foobar'           => ['foobar', 'Zm9vYmFy'],
            // 0xFB 0xFF 0xFE is standard base64 "+//+"; both alphabet
            // substitutions must land in one vector.
            'plus-and-slash'   => ["\xfb\xff\xfe", '-__-'],
        ];
    }

    #[DataProvider('encodeVectors')]
    public function testEncodeMatchesThePublishedVectors(string $bytes, string $expected): void
    {
        self::assertSame($expected, Base64Url::encode($bytes));
    }

    #[DataProvider('encodeVectors')]
    public function testEncodeNeverEmitsTheStandardAlphabetOrPadding(string $bytes, string $ignoredExpected): void
    {
        $encoded = Base64Url::encode($bytes);

        self::assertStringNotContainsString('+', $encoded);
        self::assertStringNotContainsString('/', $encoded);
        self::assertStringNotContainsString('=', $encoded);
    }

    #[DataProvider('encodeVectors')]
    public function testLaxDecodeIsTheLeftInverseOfEncodeOnEveryVector(string $bytes, string $encoded): void
    {
        self::assertSame($bytes, Base64Url::decode($encoded));
        self::assertSame($bytes, Base64Url::decode($encoded, true));
    }

    public function testDecodeMapsDashAndUnderscoreBackToPlusAndSlash(): void
    {
        self::assertSame("\xfb\xff\xfe", Base64Url::decode('-__-'));
    }

    public function testDecodeAcceptsPaddedInput(): void
    {
        // Call sites today receive unpadded fragments, but the underlying
        // kernel has never refused padding; preserving that acceptance keeps
        // every migration arm byte-neutral.
        self::assertSame('foob', Base64Url::decode('Zm9vYg=='));
        self::assertSame('a', Base64Url::decode('YQ=='));
    }

    public function testLaxArmStripsIllegalCharactersAndDecodesTheRest(): void
    {
        // Control: the clean string decodes.
        self::assertSame('Hello', Base64Url::decode('SGVsbG8'));

        // Lax today: base64_decode() without the strict flag silently drops
        // out-of-alphabet characters — the legacy server Auth/JwtHandler arm.
        self::assertSame('Hello', Base64Url::decode('SGVsbG8*'));
        self::assertSame('', Base64Url::decode('###'));
    }

    public function testStrictArmRefusesMalformedInputWithEmptyString(): void
    {
        // Control: the clean string still decodes strictly.
        self::assertSame('Hello', Base64Url::decode('SGVsbG8', true));

        // The strictest decode arm (server HubJwtValidator / hub JwtHandler
        // lineage): out-of-alphabet characters collapse the whole input to
        // '' — never a partial decode, never an implicit false.
        self::assertSame('', Base64Url::decode('SGVsbG8*', true));
        self::assertSame('', Base64Url::decode('###', true));
    }

    public function testDecodeNeverReturnsFalseAndEmptyInputDecodesToEmptyString(): void
    {
        // The "NO implicit false" law: failure collapses to '' as a string
        // even where the underlying base64_decode() returned false.
        self::assertSame('', Base64Url::decode('SGVsbG8*', true));
        self::assertSame('', Base64Url::decode('', true));
        self::assertSame('', Base64Url::decode(''));
    }

    public function testRoundTripAcrossEveryByteValueAndEveryLength(): void
    {
        $allBytes = '';
        for ($i = 0; $i < 256; $i++) {
            $allBytes .= chr($i);
        }

        for ($length = 0; $length <= 512; $length++) {
            $bytes = substr(str_repeat($allBytes, 2), 0, $length);
            self::assertSame($bytes, Base64Url::decode(Base64Url::encode($bytes), true), "length {$length}");
        }
    }
}
