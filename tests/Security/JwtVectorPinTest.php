<?php

/**
 * Jwt Vector Pin Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Security;

use Phlix\Shared\Security\Jwt;
use Phlix\Shared\Security\JwtException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Frozen wire vectors for {@see Jwt}.
 *
 * Every token below is a literal, produced once from the canonical recipe
 * (`rtrim(strtr(base64_encode(…))` segments + raw-binary HMAC) against the
 * pinned secret — never by calling the code under test, so an
 * implementation that merely agrees with itself cannot pass. The set covers
 * BOTH live header shapes in the estate: the strict `{"alg","typ"}` header
 * that phlix-server and phlix-hub mint today, and the typ-less legacy header
 * older verifiers have always had to keep accepting; plus the `alg:none`
 * attack token (bug2) as a permanent regression pin.
 *
 * @package Phlix\Shared\Tests\Security
 * @since 0.56.0
 */
#[CoversClass(Jwt::class)]
final class JwtVectorPinTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef-shared-v056';

    /** Serialized claims shared by the minted vectors: sub=user-1, iat/exp fixed. */
    private const CLAIMS = ['sub' => 'user-1', 'iat' => 1700000000, 'exp' => 1800000000];

    /** Header {"alg":"HS256","typ":"JWT"} — the estate-wide mint shape today. */
    private const TOKEN_STRICT =
        'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'
        . '.eyJzdWIiOiJ1c2VyLTEiLCJpYXQiOjE3MDAwMDAwMDAsImV4cCI6MTgwMDAwMDAwMH0'
        . '.zKyAqoD_Y3QPjkBo6qnOEDK2geVJmX2pw4QhM4V50Ws';

    /** Header {"alg":"HS256"} — typ-less legacy shape, still valid. */
    private const TOKEN_LEGACY =
        'eyJhbGciOiJIUzI1NiJ9'
        . '.eyJzdWIiOiJ1c2VyLTEiLCJpYXQiOjE3MDAwMDAwMDAsImV4cCI6MTgwMDAwMDAwMH0'
        . '.Wk93T2nfYsHMqZakDuUnOgOy_TgWUF6eBM4HbulPPOU';

    /** Header {"alg":"none","typ":"JWT"} with an HONEST HMAC — the bug2 token. */
    private const TOKEN_ALG_NONE =
        'eyJhbGciOiJub25lIiwidHlwIjoiSldUIn0'
        . '.eyJzdWIiOiJ1c2VyLTEiLCJpYXQiOjE3MDAwMDAwMDAsImV4cCI6MTgwMDAwMDAwMH0'
        . '.pvXz-aXCVNSSAgsJu-WIW5Ve07xBs0m7a2HUeLcxRGA';

    public function testStrictHeaderVectorVerifiesToExactParts(): void
    {
        $parts = Jwt::verify(self::TOKEN_STRICT, self::SECRET);

        self::assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $parts->header);
        self::assertSame(self::CLAIMS, $parts->payload);
    }

    public function testLegacyHeaderVectorVerifiesAndShowsNoTyp(): void
    {
        $parts = Jwt::verify(self::TOKEN_LEGACY, self::SECRET);

        self::assertSame(['alg' => 'HS256'], $parts->header);
        self::assertSame(self::CLAIMS, $parts->payload);
    }

    public function testCreateReproducesTheStrictVectorByteForByte(): void
    {
        // Mint-side drift alarm: any change to segment serialization or header
        // key order reddens this before it ever hits a verifier.
        self::assertSame(self::TOKEN_STRICT, Jwt::create(self::CLAIMS, self::SECRET));
    }

    public function testAlgNoneVectorIsRefusedByTheHeaderPinForever(): void
    {
        // bug2 regression, frozen as bytes: server-side fix 889610f1-era pin,
        // hub-side pin, and now the shared primitive — three homes, one token.
        try {
            Jwt::verify(self::TOKEN_ALG_NONE, self::SECRET);
            self::fail('the alg:none vector must never verify');
        } catch (JwtException $e) {
            self::assertSame(JwtException::REASON_ALG_UNEXPECTED, $e->reason);
        }
    }
}
