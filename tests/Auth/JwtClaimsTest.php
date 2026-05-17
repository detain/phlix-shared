<?php

declare(strict_types=1);

namespace Phlex\Shared\Tests\Auth;

use InvalidArgumentException;
use Phlex\Shared\Auth\JwtClaims;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Shared\Auth\JwtClaims
 */
final class JwtClaimsTest extends TestCase
{
    public function test_fromPayload_with_full_payload_roundtrips(): void
    {
        $payload = [
            'iss' => JwtClaims::ISS_PHLEX_HUB,
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
            'iss' => JwtClaims::ISS_PHLEX,
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
            'iss' => 'phlex', 'sub' => 'u', 'iat' => 'not-int', 'exp' => 2, 'type' => 'access',
        ]);
    }

    public function test_fromPayload_invalid_aud_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "aud" must be a string.');
        JwtClaims::fromPayload([
            'iss' => 'phlex', 'aud' => 42, 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);
    }

    public function test_fromPayload_invalid_nbf_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "nbf"');
        JwtClaims::fromPayload([
            'iss' => 'phlex', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access', 'nbf' => 'oops',
        ]);
    }

    public function test_fromPayload_invalid_jti_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "jti"');
        JwtClaims::fromPayload([
            'iss' => 'phlex', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access', 'jti' => 42,
        ]);
    }

    public function test_fromPayload_invalid_scope_shape_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "scope"');
        JwtClaims::fromPayload([
            'iss' => 'phlex', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access', 'scope' => 'oops',
        ]);
    }

    public function test_fromPayload_invalid_scope_entry_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only strings');
        JwtClaims::fromPayload([
            'iss' => 'phlex', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access', 'scope' => ['ok', 42],
        ]);
    }

    public function test_fromPayload_invalid_serverId_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "serverId"');
        JwtClaims::fromPayload([
            'iss' => 'phlex', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access', 'serverId' => 99,
        ]);
    }

    public function test_isExpired_compares_exp_to_now(): void
    {
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlex', 'sub' => 'u', 'iat' => 1, 'exp' => 100, 'type' => 'access',
        ]);

        $this->assertTrue($claims->isExpired(200));
        $this->assertFalse($claims->isExpired(50));
        // Uses current time when null
        $this->assertTrue($claims->isExpired(null));
    }

    public function test_hasScope_true_when_scope_present(): void
    {
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlex', 'sub' => 'u', 'iat' => 1, 'exp' => 200, 'type' => 'access',
            'scope' => ['library:read'],
        ]);

        $this->assertTrue($claims->hasScope('library:read'));
        $this->assertFalse($claims->hasScope('library:write'));
    }

    public function test_toPayload_omits_optional_null_fields(): void
    {
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlex', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);

        $payload = $claims->toPayload();
        $this->assertArrayNotHasKey('nbf', $payload);
        $this->assertArrayNotHasKey('jti', $payload);
        $this->assertArrayNotHasKey('scope', $payload);
        $this->assertArrayNotHasKey('serverId', $payload);
    }
}
