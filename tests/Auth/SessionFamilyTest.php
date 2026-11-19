<?php

/**
 * Session Family Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Auth;

use Phlix\Shared\Auth\JwtClaims;
use Phlix\Shared\Auth\SessionFamily;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Phlix\Shared\Auth\SessionFamily::class)]
final class SessionFamilyTest extends TestCase
{
    public function test_claim_key_literals_are_frozen(): void
    {
        // B-02: these strings are the cross-daemon wire keys. A rename here
        // without a coordinated consumer release splits every in-flight
        // session family in half — the pin below is the alarm for that.
        $this->assertSame('sid', SessionFamily::CLAIM_SESSION_ID);
        $this->assertSame('profile_id', SessionFamily::CLAIM_PROFILE_ID);
    }

    public function test_rotation_grace_window_literal(): void
    {
        // Verified identical server (AuthManager) and hub (AuthManager)
        // before the move (M-04); the number itself is a wire-visible
        // decision boundary, so it is frozen, not derived.
        $this->assertSame(10, SessionFamily::ROTATION_GRACE_SECONDS);
    }

    public function test_type_values_alias_the_single_definition_on_jwt_claims(): void
    {
        // One definition site per literal: SessionFamily names the family
        // vocabulary, JwtClaims already owns the token-kind values.
        $this->assertSame('access', SessionFamily::TYPE_ACCESS);
        $this->assertSame('refresh', SessionFamily::TYPE_REFRESH);
        $this->assertSame(JwtClaims::TYPE_ACCESS, SessionFamily::TYPE_ACCESS);
        $this->assertSame(JwtClaims::TYPE_REFRESH, SessionFamily::TYPE_REFRESH);
    }

    public function test_the_family_claims_are_distinct_from_token_types(): void
    {
        // Sanity guard against copy-paste drift of the value set: no two
        // vocabulary members may collide.
        $values = [
            SessionFamily::CLAIM_SESSION_ID,
            SessionFamily::CLAIM_PROFILE_ID,
            SessionFamily::TYPE_ACCESS,
            SessionFamily::TYPE_REFRESH,
        ];

        $this->assertCount(4, array_unique($values));
    }
}
