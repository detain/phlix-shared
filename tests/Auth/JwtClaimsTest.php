<?php

/**
 * Jwt Claims Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Auth;

use InvalidArgumentException;
use Phlix\Shared\Auth\JwtClaims;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Phlix\Shared\Auth\JwtClaims::class)]
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

    public function test_isExpired_is_true_at_the_exp_boundary(): void
    {
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 100, 'type' => 'access',
        ]);

        $this->assertFalse($claims->isExpired(99));
        $this->assertTrue($claims->isExpired(100)); // RFC 7519: exp <= now
        $this->assertTrue($claims->isExpired(101));
    }

    // --- phlix-shared-015: isNotYetValid() nbf boundary trio -------------
    // Mirrors the isExpired boundary tests above. isExpired was pinned
    // shared-side while isNotYetValid had zero coverage here (the only
    // executable nbf boundary lived in the hub); these close the asymmetry.

    public function test_isNotYetValid_compares_nbf_to_now(): void
    {
        // nbf in the past → the token is acceptable (not "not yet valid");
        // nbf in the future relative to the injected clock → refused.
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 1000, 'type' => 'access',
            'nbf' => 100,
        ]);

        $this->assertFalse($claims->isNotYetValid(0, 200)); // now past nbf → valid
        $this->assertTrue($claims->isNotYetValid(0, 50));   // now before nbf → not yet valid
        // Uses current time when null: nbf=100 is far before any real clock.
        $this->assertFalse($claims->isNotYetValid(0, null));
    }

    public function test_isNotYetValid_is_false_at_the_nbf_boundary(): void
    {
        // Mirror of test_isExpired_is_true_at_the_exp_boundary, with the
        // opposite boundary polarity: the predicate is `nbf > $now`, so a token
        // becomes acceptable exactly AT its nbf instant (RFC 7519 §4.1.5 — the
        // boundary second is valid, unlike `exp` where it is already expired).
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 1000, 'type' => 'access',
            'nbf' => 100,
        ]);

        $this->assertTrue($claims->isNotYetValid(0, 99));
        $this->assertFalse($claims->isNotYetValid(0, 100)); // nbf == now → valid
        $this->assertFalse($claims->isNotYetValid(0, 101));
    }

    public function test_isNotYetValid_absent_nbf_is_always_valid(): void
    {
        // The early-exit arm: a token with no nbf claim is valid from the moment
        // it is issued, regardless of the injected now (past or future) and
        // regardless of leeway.
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 1000, 'type' => 'access',
        ]);

        $this->assertNull($claims->nbf);
        $this->assertFalse($claims->isNotYetValid());
        $this->assertFalse($claims->isNotYetValid(0, 1));
        $this->assertFalse($claims->isNotYetValid(0, time() - 100000));
        $this->assertFalse($claims->isNotYetValid(3600, time() + 100000));
    }

    public function test_isNotYetValid_far_future_nbf_uses_current_time_when_null(): void
    {
        // The null-$now seam on the future side: a deliberately far-future nbf
        // is refused against the real clock, deterministically (year 2096).
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 4000003600,
            'type' => 'access', 'nbf' => 4000000000,
        ]);

        $this->assertTrue($claims->isNotYetValid(0, null));
    }

    public function test_isNotYetValid_leeway_forgives_bounded_clock_skew(): void
    {
        // The $leeway seam: server's OIDC posture tolerates bounded provider
        // skew (`$nbf > time() + 60`) while the hub calls with zero leeway. A
        // token dated ahead of the clock within the budget is accepted; beyond
        // the budget, refused; at the budget edge, accepted (now+leeway == nbf).
        $claims = JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 1000, 'type' => 'access',
            'nbf' => 1000,
        ]);

        // nbf sits 50s ahead of now=950.
        $this->assertTrue($claims->isNotYetValid(0, 950));    // strict (hub): 50s ahead refused
        $this->assertFalse($claims->isNotYetValid(60, 950));  // 60s skew (server): accepted
        $this->assertTrue($claims->isNotYetValid(40, 950));   // 40s leeway < 50s gap: refused
        $this->assertFalse($claims->isNotYetValid(50, 950));  // gap == leeway boundary: accepted
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

    // --- LW-059 [review shared__srphp #5]: empty-string claims are absence ---

    public function test_fromPayload_empty_iss_throws(): void
    {
        // Red-first at the parent commit: requireString accepted '' happily,
        // contradicting the class header's "any non-empty string" law.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "iss" must be a non-empty string.');

        JwtClaims::fromPayload([
            'iss' => '', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);
    }

    public function test_fromPayload_empty_sub_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "sub" must be a non-empty string.');

        JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => '', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);
    }

    public function test_fromPayload_empty_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "type" must be a non-empty string.');

        JwtClaims::fromPayload([
            'iss' => 'phlix', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => '',
        ]);
    }

    public function test_fromPayload_present_empty_aud_throws(): void
    {
        // The legacy tolerance is for a MISSING `aud`; an explicitly empty one
        // is a malformed token, not a v0.10.x payload. Red-first at the parent
        // commit: '' passed the is_string gate and was kept.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "aud" must be a non-empty string.');

        JwtClaims::fromPayload([
            'iss' => 'phlix', 'aud' => '', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);
    }

    public function test_fromPayloadStrict_empty_aud_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "aud" must be a non-empty string.');

        JwtClaims::fromPayloadStrict([
            'iss' => 'phlix', 'aud' => '', 'sub' => 'u', 'iat' => 1, 'exp' => 2, 'type' => 'access',
        ]);
    }

    public function test_empty_aud_roundtrip_is_now_fail_loud(): void
    {
        // Round-trip semantics with empty `aud` pinned: the constructor stays
        // a typed holder (minting side is trusted), so a directly-built
        // empty-aud object still serializes aud verbatim — but feeding that
        // payload back through fromPayload() now FAILS LOUD instead of
        // silently reconstructing an equal object. Empty-string claims must
        // not survive a wire round-trip. Red-first at the parent commit,
        // which round-tripped it silently.
        $claims = new JwtClaims(
            iss: 'phlix',
            aud: '',
            sub: 'u',
            iat: 1,
            exp: 2,
            nbf: null,
            type: 'access',
            jti: null,
            scope: [],
            serverId: null,
        );

        $this->assertSame('', $claims->toPayload()['aud']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT claim "aud" must be a non-empty string.');

        JwtClaims::fromPayload($claims->toPayload());
    }
}
