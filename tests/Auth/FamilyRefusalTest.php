<?php

/**
 * Family Refusal Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Auth;

use Phlix\Shared\Auth\FamilyGrace;
use Phlix\Shared\Auth\FamilyRefusalReason;
use Phlix\Shared\Auth\GrantConsume;
use Phlix\Shared\Auth\SessionFamily;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Phlix\Shared\Auth\FamilyRefusalReason::class)]
#[CoversClass(\Phlix\Shared\Auth\GrantConsume::class)]
#[CoversClass(\Phlix\Shared\Auth\FamilyGrace::class)]
final class FamilyRefusalTest extends TestCase
{
    /**
     * The audit vocabulary, exactly as the server's adjudication ladder
     * emits it today (grep census of AuthManager logFailedAuth call sites at
     * extraction). The migration swaps literals for `->value` reads byte for
     * byte; this set is what must stay frozen.
     *
     * @return array<string, array{0: string}>
     */
    public static function reasonVectorProvider(): array
    {
        return [
            'StoreUnwired' => ['token_store_unwired'],
            'GrantJtiMissing' => ['refresh_grant_jti_missing'],
            'GrantUnknown' => ['refresh_grant_unknown'],
            'SessionRevoked' => ['token_session_revoked'],
            'GrantSessionMismatch' => ['refresh_grant_session_mismatch'],
            'GrantConsumeLost' => ['refresh_grant_consume_lost'],
            'ProfileNotOwned' => ['profile_not_owned'],
            'TokenReuse' => ['refresh_token_reuse'],
        ];
    }

    /**
     * @param string $value reason literal
     */
    #[DataProvider('reasonVectorProvider')]
    public function test_refusal_reason_values_are_the_frozen_server_literals(string $value): void
    {
        $case = FamilyRefusalReason::from($value);

        $this->assertSame($value, $case->value);
    }

    public function test_the_ladder_has_exactly_eight_arms(): void
    {
        $this->assertCount(8, FamilyRefusalReason::cases());

        $expected = [
            'token_store_unwired',
            'refresh_grant_jti_missing',
            'refresh_grant_unknown',
            'token_session_revoked',
            'refresh_grant_session_mismatch',
            'refresh_grant_consume_lost',
            'profile_not_owned',
            'refresh_token_reuse',
        ];

        $this->assertSame($expected, array_column(FamilyRefusalReason::cases(), 'value'));
    }

    public function test_grant_consume_carries_three_verdicts(): void
    {
        $this->assertSame(
            ['consumed', 'not_consumed', 'indeterminate'],
            array_column(GrantConsume::cases(), 'value')
        );
    }

    public function test_within_grace_decision_table(): void
    {
        // Boundary law: the final in-window second replays benign, the first
        // out-of-window second is reuse. `<=` mirrors the repos' SQL exactly
        // (consumed_at >= now - g): same second -> inside.
        $this->assertTrue(FamilyGrace::withinGrace(1000, 1000));
        $this->assertTrue(FamilyGrace::withinGrace(1000, 1010));
        $this->assertFalse(FamilyGrace::withinGrace(1000, 1011));
    }

    public function test_future_consumption_clock_skew_stays_inside_grace(): void
    {
        // A consumedAt ahead of now (clock moved back after the write)
        // satisfies the SQL DATE_SUB comparison; the arithmetic must not
        // invent a stricter law — fresh writes never adjudicate out-of-grace.
        $this->assertTrue(FamilyGrace::withinGrace(1005, 1000));
    }

    public function test_null_grace_falls_back_to_the_family_default(): void
    {
        $this->assertTrue(FamilyGrace::withinGrace(1000, 1010, null));
        $this->assertFalse(FamilyGrace::withinGrace(1000, 1011, null));
        // Same answers as the explicit default — the null arm is the caller's
        // absent store field, not a different window.
        $this->assertSame(
            FamilyGrace::withinGrace(1000, 1011, null),
            FamilyGrace::withinGrace(1000, 1011, SessionFamily::ROTATION_GRACE_SECONDS)
        );
    }

    public function test_custom_grace_window_is_honoured(): void
    {
        $this->assertTrue(FamilyGrace::withinGrace(1000, 1000, 0));
        $this->assertFalse(FamilyGrace::withinGrace(1000, 1001, 0));
        $this->assertTrue(FamilyGrace::withinGrace(1000, 1030, 30));
        $this->assertFalse(FamilyGrace::withinGrace(1000, 1031, 30));
    }
}
