<?php

/**
 * Jwt Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Security;

use InvalidArgumentException;
use Phlix\Shared\Security\Jwt;
use Phlix\Shared\Security\JwtException;
use Phlix\Shared\Security\JwtTokenParts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function explode;
use function hash_hmac;
use function json_encode;
use function rtrim;
use function strtr;

/**
 * Behavioral pins for the HS256 primitive — above all the ORDER of the
 * checks: an attack token whose HMAC is mathematically valid must still be
 * refused by the header pin, so the thrown reason proves the pin ran first.
 *
 * @package Phlix\Shared\Tests\Security
 * @since 0.56.0
 */
#[CoversClass(Jwt::class)]
#[CoversClass(JwtException::class)]
#[CoversClass(JwtTokenParts::class)]
final class JwtTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef-shared-v056';

    /** base64url in test-side form, for hand-forging attack segments. */
    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Hand-forge a token: segments as given, signature HMAC'd honestly. */
    private static function forge(string $headerJson, string $payloadJson, string $secret = self::SECRET): string
    {
        $h = self::b64($headerJson);
        $p = self::b64($payloadJson);

        return $h . '.' . $p . '.' . self::b64(hash_hmac('sha256', "{$h}.{$p}", $secret, true));
    }

    /**
     * Hand-forge from PRE-ENCODED segments (malleable variants forge()'s
     * canonical b64() cannot produce), signature HMAC'd honestly over
     * exactly those raw bytes — the shape a token-malleability probe needs.
     */
    private static function forgeRaw(
        string $headerEncoded,
        string $payloadEncoded,
        string $secret = self::SECRET
    ): string {
        $raw = "{$headerEncoded}.{$payloadEncoded}";

        return $raw . '.' . self::b64(hash_hmac('sha256', $raw, $secret, true));
    }

    public function testCreateVerifyRoundTripExposesBothSegments(): void
    {
        $claims = ['sub' => 'user-1', 'scope' => ['read', 'write'], 'iat' => 1700000000];

        $token = Jwt::create($claims, self::SECRET);
        $parts = Jwt::verify($token, self::SECRET);

        self::assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $parts->header);
        self::assertSame($claims, $parts->payload);
    }

    public function testMintedHeaderIsTheFixedKeyOrderedObject(): void
    {
        $token = Jwt::create(['sub' => 'x'], self::SECRET);
        [$headerEncoded] = explode('.', $token);

        self::assertSame('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $headerEncoded);
    }

    public function testSignIsTheRawSegmentSeamAndMatchesCreateByteForByte(): void
    {
        $headerJson = '{"alg":"HS256","typ":"JWT"}';
        $payloadJson = '{"sub":"x"}';

        self::assertSame(
            Jwt::sign($headerJson, $payloadJson, self::SECRET),
            Jwt::create(['sub' => 'x'], self::SECRET),
        );
    }

    public function testTamperedPayloadFailsSignatureWithTheNamedReason(): void
    {
        $token = Jwt::create(['role' => 'member'], self::SECRET);
        [$h, , $s] = explode('.', $token);
        $evil = self::b64('{"role":"admin"}');

        try {
            Jwt::verify("{$h}.{$evil}.{$s}", self::SECRET);
            self::fail('tampered payload must not verify');
        } catch (JwtException $e) {
            self::assertSame(JwtException::REASON_SIGNATURE_MISMATCH, $e->reason);
        }
    }

    public function testWrongSecretFailsSignature(): void
    {
        $token = Jwt::create(['sub' => 'x'], self::SECRET);

        try {
            Jwt::verify($token, 'ffff56789abcdef0123456789abcdef-shared-v056');
            self::fail('foreign secret must not verify');
        } catch (JwtException $e) {
            self::assertSame(JwtException::REASON_SIGNATURE_MISMATCH, $e->reason);
        }
    }

    public function testAlgNoneWithAHonestHmacIsRefusedByTheHeaderPinNotTheSignature(): void
    {
        // THE regression: attacker labels the token alg:none but signs it
        // correctly (they know the scheme, just not the secret). A decoder
        // that verifies first, then consults alg, would accept it.
        $token = self::forge('{"alg":"none","typ":"JWT"}', '{"sub":"victim"}');

        try {
            Jwt::verify($token, self::SECRET);
            self::fail('alg:none must be refused before signature work');
        } catch (JwtException $e) {
            self::assertSame(JwtException::REASON_ALG_UNEXPECTED, $e->reason);
        }
    }

    public function testAlgSwapToAnotherHmacFamilyIsRefused(): void
    {
        // Same attack shape with a plausible label: HS512-signed-by-us HS256-HMAC.
        $token = self::forge('{"alg":"HS512","typ":"JWT"}', '{"sub":"victim"}');

        $this->expectExceptionObject(JwtException::algUnexpected('Unexpected JWT alg (expected HS256).'));
        Jwt::verify($token, self::SECRET);
    }

    public function testLowercaseAlgLabelIsRefused(): void
    {
        // Case-sensitivity is the hub Pkce law carried into the JWT header.
        $token = self::forge('{"alg":"hs256","typ":"JWT"}', '{"sub":"x"}');

        try {
            Jwt::verify($token, self::SECRET);
            self::fail('`hs256` is not the RFC algorithm name');
        } catch (JwtException $e) {
            self::assertSame(JwtException::REASON_ALG_UNEXPECTED, $e->reason);
        }
    }

    public function testNonStringAlgLabelIsRefusedAsMalformedHeaderContent(): void
    {
        $token = self::forge('{"alg":256,"typ":"JWT"}', '{"sub":"x"}');

        try {
            Jwt::verify($token, self::SECRET);
            self::fail('numeric alg must not pass the is_string guard');
        } catch (JwtException $e) {
            self::assertSame(JwtException::REASON_ALG_UNEXPECTED, $e->reason);
        }
    }

    public function testForeignTypIsRefusedButAbsentTypIsAccepted(): void
    {
        $withTyp = self::forge('{"alg":"HS256","typ":"JWE"}', '{"sub":"x"}');
        try {
            Jwt::verify($withTyp, self::SECRET);
            self::fail('typ:JWE must be refused');
        } catch (JwtException $e) {
            self::assertSame(JwtException::REASON_ALG_UNEXPECTED, $e->reason);
        }

        // Both parents' isset() arm: legacy headers without typ stay valid.
        $legacy = self::forge('{"alg":"HS256"}', '{"sub":"x"}');
        self::assertSame('x', Jwt::verify($legacy, self::SECRET)->payload['sub']);
    }

    public function testSegmentCountIsEnforced(): void
    {
        foreach (['', 'a', 'a.b', 'a.b.c.d'] as $shape) {
            try {
                Jwt::verify($shape, self::SECRET);
                self::fail("shape '{$shape}' must be malformed");
            } catch (JwtException $e) {
                self::assertSame(JwtException::REASON_MALFORMED, $e->reason);
            }
        }
    }

    public function testGarbageHeaderSegmentIsMalformedNotSilentlyNull(): void
    {
        // The JSON_THROW_ON_ERROR (hub) law over the server's silent-null arm:
        // undecodable bytes must fail at the header stage regardless of the
        // signature that follows them.
        $badHeader = self::b64('not-json-at-all');
        $p = self::b64('{"sub":"x"}');
        $s = self::b64(hash_hmac('sha256', "{$badHeader}.{$p}", self::SECRET, true));

        try {
            Jwt::verify("{$badHeader}.{$p}.{$s}", self::SECRET);
            self::fail('garbage header must be malformed');
        } catch (JwtException $e) {
            self::assertSame(JwtException::REASON_MALFORMED, $e->reason);
        }
    }

    public function testPaddedHeaderSegmentIsRefusedAsMalformed(): void
    {
        // phlix-shared-007 (token malleability): '=' padding encodes the SAME
        // header bytes as the canonical segment (this 23-byte JSON standard-
        // encodes as the canonical 31 chars plus one '='), so under a lenient
        // decoder the two byte-distinct tokens verify identically. The segment
        // arm refuses padding, and it fires at the HEADER stage — before any
        // signature work.
        $paddedHeader = self::b64('{"alg":"HS256","x":"y"}') . '=';
        $token = self::forgeRaw($paddedHeader, self::b64('{"sub":"x"}'));

        try {
            Jwt::verify($token, self::SECRET);
            self::fail('padded header segment must not verify');
        } catch (JwtException $e) {
            self::assertSame(JwtException::REASON_MALFORMED, $e->reason);
        }

        // Control: the canonical encoding of the same header bytes still verifies.
        $canonical = self::forgeRaw(self::b64('{"alg":"HS256","x":"y"}'), self::b64('{"sub":"x"}'));
        self::assertSame('x', Jwt::verify($canonical, self::SECRET)->payload['sub']);
    }

    public function testPaddedPayloadSegmentIsRefusedAsMalformed(): void
    {
        // The payload half of the same malleability: an honest HMAC over the
        // padded segment must not buy the token a pass.
        $p = self::b64('{"sub":"x"}');
        $h = self::b64('{"alg":"HS256","typ":"JWT"}');

        try {
            Jwt::verify(self::forgeRaw($h, $p . '='), self::SECRET);
            self::fail('padded payload segment must not verify');
        } catch (JwtException $e) {
            self::assertSame(JwtException::REASON_MALFORMED, $e->reason);
        }

        // Control: the canonical payload verifies.
        self::assertSame('x', Jwt::verify(self::forgeRaw($h, $p), self::SECRET)->payload['sub']);
    }

    public function testStandardAlphabetPayloadSegmentIsRefusedAsMalformed(): void
    {
        // The third malleable form: '+' and '/' are outside base64url's own
        // alphabet, so encode() never emits them — but a lenient decoder
        // accepts them, minting a second byte-distinct spelling of one token.
        // '{"sub":"~~~"}' standard-encodes as 'eyJzdWIiOiJ+fn4ifQ=='.
        $standard = base64_encode('{"sub":"~~~"}');
        $h = self::b64('{"alg":"HS256","typ":"JWT"}');

        try {
            Jwt::verify(self::forgeRaw($h, $standard), self::SECRET);
            self::fail('standard-alphabet payload segment must not verify');
        } catch (JwtException $e) {
            self::assertSame(JwtException::REASON_MALFORMED, $e->reason);
        }

        // Control: the canonical url-safe form of the same bytes verifies.
        $canonicalPayload = self::b64('{"sub":"~~~"}');
        self::assertSame(
            '~~~',
            Jwt::verify(self::forgeRaw($h, $canonicalPayload), self::SECRET)->payload['sub'],
        );
    }

    public function testScalarJsonPayloadIsRejectedAsMalformed(): void
    {
        // Decodes fine, but is not an object — both parents' is_array arm.
        $token = self::forge('{"alg":"HS256","typ":"JWT"}', '"a string"');

        try {
            Jwt::verify($token, self::SECRET);
            self::fail('scalar payload must be malformed');
        } catch (JwtException $e) {
            self::assertSame(JwtException::REASON_MALFORMED, $e->reason);
        }
    }

    public function testNumericPayloadKeysAreDroppedNotExportedToIntOffsets(): void
    {
        // The parents' string-key filter loop, pinned.
        $token = self::forge('{"alg":"HS256","typ":"JWT"}', '{"0":"zero","sub":"x"}');
        $parts = Jwt::verify($token, self::SECRET);

        self::assertSame(['sub' => 'x'], $parts->payload);
    }

    public function testExtraHeaderCanAddKeysButNeverMoveThePin(): void
    {
        $token = Jwt::create(['sub' => 'x'], self::SECRET, ['kid' => 'relay-1']);
        $parts = Jwt::verify($token, self::SECRET);

        self::assertSame('relay-1', $parts->header['kid']);
        self::assertSame(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'relay-1'], $parts->header);

        // Repeating the fixed values is a no-op; contradicting them is refused.
        self::assertSame(
            Jwt::create(['sub' => 'x'], self::SECRET),
            Jwt::create(['sub' => 'x'], self::SECRET, ['alg' => 'HS256', 'typ' => 'JWT']),
        );

        $this->expectException(InvalidArgumentException::class);
        Jwt::create(['sub' => 'x'], self::SECRET, ['alg' => 'none']);
    }

    public function testShortSecretIsRefusedAtEverySeam(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Jwt::create(['sub' => 'x'], 'tooshort');
    }

    public function testVerifyAlsoEnforcesTheSecretFloorBeforeAnyCrypto(): void
    {
        $token = Jwt::create(['sub' => 'x'], self::SECRET);

        $this->expectException(InvalidArgumentException::class);
        Jwt::verify($token, 'tooshort');
    }

    public function testCreateIsDeterministicForFixedClaims(): void
    {
        // Determinism of the primitive: same claims + same secret == same bytes.
        $claims = ['sub' => 'u', 'jti' => 'fixed-jti-1'];
        self::assertSame(Jwt::create($claims, self::SECRET), Jwt::create($claims, self::SECRET));
    }
}
