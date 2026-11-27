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
    public function testDecodeIsTheLeftInverseOfEncodeOnEveryVector(string $bytes, string $encoded): void
    {
        // Writer output is canonical, so it survives the strict arm — which
        // the default call exercises since S-shared-07 — and the explicitly
        // strict call asserts the same posture side by side.
        self::assertSame($bytes, Base64Url::decode($encoded));
        self::assertSame($bytes, Base64Url::decode($encoded, true));
    }

    public function testDecodeMapsDashAndUnderscoreBackToPlusAndSlash(): void
    {
        // '-__-' is canonical url-safe output, accepted on every arm.
        self::assertSame("\xfb\xff\xfe", Base64Url::decode('-__-'));
    }

    public function testDecodeAcceptsPaddedInput(): void
    {
        // Call sites today receive unpadded fragments, but the underlying
        // kernel has never refused padding; preserving that acceptance keeps
        // every migration arm byte-neutral. (decodeJwtSegment() below refuses
        // padding — a JWT segment is not a migration call site.)
        self::assertSame('foob', Base64Url::decode('Zm9vYg=='));
        self::assertSame('a', Base64Url::decode('YQ=='));
    }

    public function testLaxArmStripsIllegalCharactersAndDecodesTheRest(): void
    {
        // The LAX arm is explicit opt-in only since S-shared-07: it remains
        // the legacy server Auth/JwtHandler body, where base64_decode()
        // without the strict flag silently drops out-of-alphabet characters
        // and decodes what remains.
        self::assertSame('Hello', Base64Url::decode('SGVsbG8*', false));
        self::assertSame('', Base64Url::decode('###', false));
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

    public function testStrictIsTheDefaultArmSinceSShared07(): void
    {
        // Control: canonical input decodes identically on every arm.
        self::assertSame('Hello', Base64Url::decode('SGVsbG8'));

        // Conviction: out-of-alphabet input now collapses to '' on the
        // DEFAULT arm — under the pre-S-shared-07 lax default this returned
        // 'Hello' (the phlix-shared-007 malleability).
        self::assertSame('', Base64Url::decode('SGVsbG8*'));
        self::assertSame('', Base64Url::decode('###'));

        // The LAX arm stays reachable, byte-identical to the legacy server
        // Auth/JwtHandler body it was extracted from.
        self::assertSame('Hello', Base64Url::decode('SGVsbG8*', false));
    }

    public function testDecodeJwtSegmentRoundTripsCanonicalWriterOutput(): void
    {
        // Everything encode() emits survives the canonical segment arm —
        // including the zero-length case, whose '' is the legitimate
        // empty-segment verdict, not a refusal.
        self::assertSame('', Base64Url::decodeJwtSegment(Base64Url::encode('')));
        for ($length = 1; $length <= 40; $length++) {
            $bytes = random_bytes($length);
            self::assertSame(
                $bytes,
                Base64Url::decodeJwtSegment(Base64Url::encode($bytes)),
                "writer output at {$length} bytes survives the canonical segment arm"
            );
        }
    }

    public function testDecodeJwtSegmentRejectsMalleableButDecodableForms(): void
    {
        // Padding decodes on both decode() arms but the segment arm refuses
        // it: a conformant producer never pads.
        self::assertSame('a', Base64Url::decode('YQ==', true));
        self::assertSame('a', Base64Url::decodeJwtSegment('YQ'));
        self::assertSame('', Base64Url::decodeJwtSegment('YQ=='));

        // The standard alphabet (`+`/`/`) survives strtr untouched and decodes
        // under strict mode; only the url-safe form is a canonical segment.
        self::assertSame("\xfb\xff\xfe", Base64Url::decode('+//+', true));
        self::assertSame("\xfb\xff\xfe", Base64Url::decodeJwtSegment('-__-'));
        self::assertSame('', Base64Url::decodeJwtSegment('+//+'));
    }

    public function testDecodeJwtSegmentRejectsGarbageAndImpossibleLengths(): void
    {
        self::assertSame('', Base64Url::decodeJwtSegment('SGVsbG8*'));
        self::assertSame('', Base64Url::decodeJwtSegment('###'));

        // A length `1 mod 4` is an impossible base64 length; PHP's strict
        // layer rejects it too, so this leg pins the belt, not the guard.
        self::assertSame('', Base64Url::decodeJwtSegment('A'));

        // Whitespace is the non-alphabet class strict mode lets through —
        // these two convictions belong to the D-anchored alphabet guard
        // (probed: strict decode accepts "p0\n" and 'Y Q' outright).
        self::assertSame('', Base64Url::decodeJwtSegment("p0\n"));
        self::assertSame('', Base64Url::decodeJwtSegment('Y Q'));

        // Positive controls beside the refusals, per the file's house posture:
        // an arm that always returned '' would pass a refusal-only suite.
        self::assertSame('a', Base64Url::decodeJwtSegment('YQ'));
        self::assertSame('foo', Base64Url::decodeJwtSegment('Zm9v'));
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

    /**
     * v0.59 bank row 6a: the padded strict arm is the legacy WebAuthn
     * decoder's posture — invent the '=' padding back to the 4-char
     * boundary, then decode STRICTLY, so a ragged tail or embedded
     * whitespace can never be silently stripped into a shorter read the
     * way the lax arm of decode() would.
     */
    public function testDecodePaddedRoundTripsEveryWriterLengthIncludingUnpaddedRaggedTails(): void
    {
        for ($length = 1; $length <= 40; $length++) {
            $bytes = random_bytes($length);
            self::assertSame(
                $bytes,
                Base64Url::decodePadded(Base64Url::encode($bytes)),
                "writer output at {$length} bytes survives the padded strict arm"
            );
        }
    }

    public function testDecodePaddedRefusesWhatOnlyALengthTolerantReaderWouldAccept(): void
    {
        // Garbage and embedded whitespace are refused outright, matching
        // the consumer's `=== false || === ''` throw guard collapsed to
        // this class' '' law; the differential harness pinned the whole
        // grid (gates/item6-differential.log).
        self::assertSame('', Base64Url::decodePadded('!!!!'));
        self::assertSame('', Base64Url::decodePadded("p0\n"));
        self::assertSame('', Base64Url::decodePadded('AA AA'));
        self::assertSame('', Base64Url::decodePadded('AAAAA'));
        self::assertSame('', Base64Url::decodePadded(''));

        // The url alphabet is fine here (normalized first, like the
        // consumer did), and standard alphabet passes through untouched.
        self::assertSame(base64_decode('ab+c/d==', true), Base64Url::decodePadded('ab-c_d'));
        self::assertSame('foo', Base64Url::decodePadded('Zm9v'));
    }
}
