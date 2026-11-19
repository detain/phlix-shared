<?php

/**
 * Password Hasher Tests — canonical cost set, lockstep burn coupling,
 * rehash-on-login composition.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Phlix\Shared\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * Locks the estate SSOT contract (SRV-4.1 lineage): the canonical options
 * literal, the fixed timing-burn dummy's cost coupling to it, the forward-
 * only property (canonical mints never churn), the built-in burn at the
 * missing-hash seam, and hashOrRehash's never-re-mint-on-wrong-password law.
 */
#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    /** A deliberately cheap non-canonical argon2id mint (keeps the suite fast). */
    private const CHEAP_HASH = '$argon2id$v=19$m=1024,t=1,p=1$Y2hhbmdlLXVzLXNhbHQ$9Xq0Y0kM0GZ1F0FTHLZ0FVW1cM6Z3ZGJqU5Fqk5F0M4';

    public function test_the_canonical_options_are_the_measured_php_8_3_defaults(): void
    {
        self::assertSame(
            ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
            PasswordHasher::ARGON2ID_OPTIONS,
            'Canonical cost set is frozen; changing it requires regenerating TIMING_BURN_DUMMY_HASH in lockstep.'
        );
    }

    public function test_the_burn_dummy_carries_the_canonical_cost_parameters(): void
    {
        $segments = explode('$', PasswordHasher::TIMING_BURN_DUMMY_HASH);
        // PHC format: ['', 'argon2id', 'v=19', 'm=...,t=...,p=...', salt, hash]
        self::assertSame('', $segments[0]);
        self::assertSame('argon2id', $segments[1]);

        $expected = sprintf(
            'm=%d,t=%d,p=%d',
            PasswordHasher::ARGON2ID_OPTIONS['memory_cost'],
            PasswordHasher::ARGON2ID_OPTIONS['time_cost'],
            PasswordHasher::ARGON2ID_OPTIONS['threads']
        );
        self::assertSame(
            $expected,
            $segments[3],
            'Burn dummy drifted from the canonical cost set — unknown-user logins would cost differently.'
        );
    }

    public function test_the_burn_dummy_is_a_well_formed_argon2id_hash(): void
    {
        // The dummy must verify-ably parse (a wrong candidate returns false,
        // it must not error or accept anything).
        self::assertFalse(password_verify('anything-at-all', PasswordHasher::TIMING_BURN_DUMMY_HASH));
        self::assertFalse(password_verify('', PasswordHasher::TIMING_BURN_DUMMY_HASH));
    }

    public function test_hash_mints_canonical_argon2id_and_round_trips(): void
    {
        $hash = PasswordHasher::hash('correct horse battery staple');

        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=4,p=1$', $hash);
        self::assertTrue(PasswordHasher::verify('correct horse battery staple', $hash));
        self::assertFalse(PasswordHasher::verify('wrong password', $hash));
    }

    public function test_two_mints_of_the_same_password_differ_by_salt(): void
    {
        self::assertNotSame(
            PasswordHasher::hash('same-secret'),
            PasswordHasher::hash('same-secret')
        );
    }

    public function test_canonical_mints_never_churn_on_rehash_answer(): void
    {
        // Forward-only property: day-one hashes minted under the stock PHP 8.3
        // defaults are canonical from birth — needsRehash() must answer false.
        self::assertFalse(PasswordHasher::needsRehash(PasswordHasher::hash('x')));
    }

    public function test_non_canonical_and_legacy_shapes_are_flagged_for_rehash(): void
    {
        $bcrypt = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertTrue(PasswordHasher::needsRehash($bcrypt));
        self::assertTrue(PasswordHasher::needsRehash(self::CHEAP_HASH));
        self::assertTrue(PasswordHasher::needsRehash('not-a-hash-at-all'));
        self::assertTrue(PasswordHasher::needsRehash(''));
    }

    public function test_verify_still_accepts_legacy_shapes_even_though_it_flags_them(): void
    {
        $bcrypt = password_hash('legacy-secret', PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertTrue(PasswordHasher::verify('legacy-secret', $bcrypt));
        self::assertTrue(PasswordHasher::needsRehash($bcrypt));
    }

    public function test_the_missing_hash_seam_answers_false_for_every_candidate(): void
    {
        self::assertFalse(PasswordHasher::verify('anything', null));
        self::assertFalse(PasswordHasher::verify('anything', ''));
        self::assertFalse(PasswordHasher::verify('', null));
    }

    public function test_hash_or_rehash_never_re_mints_on_a_wrong_password(): void
    {
        [$result, $rehashed] = PasswordHasher::hashOrRehash('wrong', self::CHEAP_HASH);

        self::assertFalse($rehashed);
        self::assertSame(self::CHEAP_HASH, $result, 'A failed verify must never mint a replacement credential.');
    }

    public function test_hash_or_rehash_leaves_a_canonical_stored_hash_in_place(): void
    {
        $canonical = PasswordHasher::hash('stable-secret');

        [$result, $rehashed] = PasswordHasher::hashOrRehash('stable-secret', $canonical);

        self::assertFalse($rehashed);
        self::assertSame($canonical, $result);
    }

    public function test_hash_or_rehash_re_mints_a_verified_non_canonical_hash_forward(): void
    {
        $cheap = password_hash('weak-cost-secret', PASSWORD_ARGON2ID, [
            'memory_cost' => 1024,
            'time_cost' => 1,
            'threads' => 1,
        ]);

        [$result, $rehashed] = PasswordHasher::hashOrRehash('weak-cost-secret', $cheap);

        self::assertTrue($rehashed);
        self::assertNotSame($cheap, $result);
        self::assertTrue(PasswordHasher::verify('weak-cost-secret', $result));
        self::assertFalse(PasswordHasher::needsRehash($result), 'The re-mint must land canonical.');
    }
}
