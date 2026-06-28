<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Auth;

use InvalidArgumentException;
use Phlix\Shared\Auth\JwtClaims;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Auth\JwtClaims
 */
final class JwtClaimsTest extends TestCase
{
    public function test_fromPayload_with_full_payload_roundtrips(): void
    {
        $payload = [
            'iss' => JwtClaims::ISS_PHLIX_HUB,
            'aud' => JwtClaims::AUD_CLIENT,
            'sub' => 'user-uuid',
            'iat' => 1700000000,
            'exp' => 1700003600,
            'type' => JwtClaims::TYPE_ACCESS,
            'nbf' => 1700000000,
            'jti' => 'token-id',
            'scope' => ['library:read', 'playback:write'],
            'serverId' => 'server-uuid',
        ];

        $claims = JwtClaims::fromPayload($payload);
        $this->assertSame($payload, $claims->toPayload());
    }

    public function test_fromPayload_with_minimal_legacy_payload(): void
    {
        $claims = JwtClaims::fromPayload([
            'iss' => JwtClaims::ISS_PHLIX,
            'sub' => 'user-uuid',
            'iat' => 1700000000,
            'exp' => 1700003600,
            'type' => JwtClaims::TYPE_ACCESS,
        ]);

        $this->assertSame(JwtClaims::AUD_SERVER, $claims->aud);
        $this->assertNull($claims->nbf);
        $this->assertNull($claims->jti);
        $this->assertSame([], $claims->scope);
        $this->assertNull($claims->serverId);
    }

    public function test_fromPayload_missing_required_iss_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "iss" is required.');
        JwtClaims::fromPayload([
            'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);
    }

    public function test_fromPayload_wrong_type_iat_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "iat" must be an integer.');
        JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 'not-int', 'exp' => 2, 'type' => 'access',
        ]);
    }

    public function test_fromPayload_invalid_aud_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "aud" must be a string.');
        JwtClaims::fromPayload([
            'iss' => 'phlix', 'aud' => 42, 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);
    }

    public function test_fromPayload_invalid_nbf_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "nbf"');
        JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access', 'nbf' => 'oops',
        ]);
    }

    public function test_fromPayload_invalid_jti_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "jti"');
        JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access', 'jti' => 42,
        ]);
    }

    public function test_fromPayload_invalid_scope_shape_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "scope"');
        JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access', 'scope' => 'oops',
        ]);
    }

    public function test_fromPayload_invalid_scope_entry_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only strings');
        JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access', 'scope' => ['ok', 42],
        ]);
    }

    public function test_fromPayload_invalid_serverId_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "serverId"');
        JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access', 'serverId' => 99,
        ]);
    }

    public function test_isExpired_compares_exp_to_now(): void
    {
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 100, 'type' => 'access',
        ]);

        $this->assertTrue($claims->isExpired(200));
        $this->assertFalse($claims->isExpired(50));
        // Uses current time when null
        $this->assertTrue($claims->isExpired(null));
    }

    public function test_hasScope_true_when_scope_present(): void
    {
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 200, 'type' => 'access',
            'scope' => ['library:read'],
        ]);

        $this->assertTrue($claims->hasScope('library:read'));
        $this->assertFalse($claims->hasScope('library:write'));
    }

    public function test_toPayload_omits_optional_null_fields(): void
    {
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);

        $payload = $claims->toPayload();
        $this->assertArrayNotHasKey('nbf', $payload);
        $this->assertArrayNotHasKey('jti', $payload);
        $this->assertArrayNotHasKey('scope', $payload);
        $this->assertArrayNotHasKey('serverId', $payload);
    }

    // --- S4: fromPayloadStrict() audience hardening -----------------------

    public function test_fromPayloadStrict_with_explicit_aud_succeeds(): void
    {
        $claims = JwtClaims::fromPayloadStrict([
            'iss' => JwtClaims::ISS_PHLIX_HUB,
            'aud' => JwtClaims::AUD_CLIENT,
            'sub' => 'user-uuid',
            'iat' => 1700000000,
            'exp' => 1700003600,
            'type' => JwtClaims::TYPE_ACCESS,
        ]);

        $this->assertSame(JwtClaims::AUD_CLIENT, $claims->aud);
        $this->assertSame(JwtClaims::ISS_PHLIX_HUB, $claims->iss);
    }

    public function test_fromPayloadStrict_missing_aud_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "aud" is required.');
        JwtClaims::fromPayloadStrict([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);
    }

    public function test_fromPayloadStrict_wrong_type_aud_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "aud" must be a string.');
        JwtClaims::fromPayloadStrict([
            'iss' => 'phlix', 'aud' => 42, 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);
    }

    public function test_fromPayloadStrict_still_validates_other_required_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "iss" is required.');
        JwtClaims::fromPayloadStrict([
            'aud' => JwtClaims::AUD_SERVER, 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);
    }

    public function test_fromPayload_still_defaults_missing_aud_for_legacy_tokens(): void
    {
        // BC: the lenient variant must keep defaulting a missing `aud`.
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);

        $this->assertSame(JwtClaims::AUD_SERVER, $claims->aud);
    }

    // --- B4: object round-trip symmetry -----------------------------------

    public function test_roundtrip_object_equality_full_claims(): void
    {
        $claims = JwtClaims::fromPayload([
            'iss' => JwtClaims::ISS_PHLIX_HUB,
            'aud' => JwtClaims::AUD_CLIENT,
            'sub' => 'user-uuid',
            'iat' => 1700000000,
            'exp' => 1700003600,
            'type' => JwtClaims::TYPE_ACCESS,
            'nbf' => 1700000000,
            'jti' => 'token-id',
            'scope' => ['library:read', 'playback:write'],
            'serverId' => 'server-uuid',
        ]);

        $this->assertEquals($claims, JwtClaims::fromPayload($claims->toPayload()));
    }

    public function test_roundtrip_object_equality_minimal_claims(): void
    {
        // toPayload() omits the null/empty optionals; fromPayload() re-defaults
        // them, so the reconstructed object must equal the original despite the
        // asymmetric array serialization.
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);

        $this->assertEquals($claims, JwtClaims::fromPayload($claims->toPayload()));
    }
}
