<?php

/**
 * Grant Consume Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Auth;

use Phlix\Shared\Auth\GrantConsume;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v0.59 bank row 5: the consume verdict gained its two accessors — the
 * bool reading consumers shim onto (`won()`) and the kernel translation
 * from a raw driver verdict (`fromWriteVerdict()`). These pins hold the
 * fail-closed law: of the three states, exactly one says the mint may
 * proceed, and the translation classifies every shape
 * `Database\WriteResult` measures.
 */
#[CoversClass(GrantConsume::class)]
final class GrantConsumeTest extends TestCase
{
    /**
     * The enum roster itself is untouched by the widening - the cases
     * and their wire values are the frozen vocabulary (S2 extraction).
     */
    public function test_the_case_roster_is_the_frozen_vocabulary(): void
    {
        self::assertSame(
            ['consumed', 'not_consumed', 'indeterminate'],
            array_map(static fn (GrantConsume $case): string => $case->value, GrantConsume::cases())
        );
    }

    /**
     * Fail-closed law: exactly one state wins. A lost race and an
     * unobservable verdict both answer false, so a shim can never route
     * a mint on doubt.
     */
    public function test_only_the_won_consume_reads_true(): void
    {
        self::assertTrue(GrantConsume::Consumed->won());
        self::assertFalse(GrantConsume::NotConsumed->won(), 'a lost race never mints');
        self::assertFalse(GrantConsume::Indeterminate->won(), 'an unobservable verdict never mints');
    }

    /**
     * @return array<string, array{mixed, GrantConsume}>
     */
    public static function provide_driver_verdicts(): array
    {
        return [
            'one row affected (first writer)' => [1, GrantConsume::Consumed],
            'many rows affected' => [7, GrantConsume::Consumed],
            'numeric string one' => ['1', GrantConsume::Consumed],
            'zero rows (guard matched nothing)' => [0, GrantConsume::NotConsumed],
            'numeric string zero' => ['0', GrantConsume::NotConsumed],
            'driver error false' => [false, GrantConsume::Indeterminate],
            'driver error null' => [null, GrantConsume::Indeterminate],
            'non-numeric garbage fails closed' => ['nope', GrantConsume::NotConsumed],
        ];
    }

    #[DataProvider('provide_driver_verdicts')]
    public function test_a_driver_verdict_translates_to_the_measured_vocabulary(
        mixed $result,
        GrantConsume $expected
    ): void {
        self::assertSame($expected, GrantConsume::fromWriteVerdict($result));
    }

    /**
     * The bool path a consumer shim exposes is verdict-for-verdict the
     * answer the landed `markGrantConsumed(): bool` math gives: won iff
     * the write observed one-or-more affected rows, false for error,
     * false for zero - no caller of the shim changes behavior.
     */
    public function test_the_won_reading_matches_the_legacy_bool_math(): void
    {
        foreach (self::provide_driver_verdicts() as $name => $case) {
            [$result] = $case;
            $legacy = !($result === false || $result === null) && (int) $result >= 1;
            self::assertSame($legacy, GrantConsume::fromWriteVerdict($result)->won(), $name);
        }
    }
}
