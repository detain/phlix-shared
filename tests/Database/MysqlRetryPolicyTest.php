<?php

/**
 * Mysql Retry Policy.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Database;

use Phlix\Shared\Database\MysqlError;
use Phlix\Shared\Database\MysqlRetryPolicy;
use Phlix\Shared\Database\MysqlRetryVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterization suite for the single execute() decision table.
 *
 * The table is the Option-B extraction of the server/hub retry arms
 * (design dossier DESIGN-TX-MUTEX.md §1.1, §1.6, §1.6b, §Q-A): one
 * classification over driver code + transaction liveness, from which
 * every consumer derives the same verbs (replay the statement? roll the
 * whole transaction? mark the connection poisoned?). The strict-ladder
 * law - never silently replay a statement that failed inside an open
 * transaction, because a gone-connection replay lands AFTER another
 * coroutine has reused the fresh socket (incident 1.1: "B landed, A
 * lost") - is pinned here as `replaysStatement() === false` for the
 * gone-in-transaction verdict.
 *
 * @see MysqlRetryPolicy
 * @see MysqlRetryVerdict
 */
#[CoversClass(MysqlRetryPolicy::class)]
#[CoversClass(MysqlRetryVerdict::class)]
final class MysqlRetryPolicyTest extends TestCase
{
    /**
     * The full decision table, driven through the shared driver-code
     * classifier's constants so a constant rename cannot silently skew
     * policy away from classification.
     *
     * @return array<string, array{0: int, 1: bool, 2: MysqlRetryVerdict}>
     */
    public static function classify_table(): array
    {
        return [
            'gone inside a transaction' => [MysqlError::CR_SERVER_GONE_ERROR, true, MysqlRetryVerdict::LOST_INSIDE_TRANSACTION],
            'lost inside a transaction' => [MysqlError::CR_SERVER_LOST, true, MysqlRetryVerdict::LOST_INSIDE_TRANSACTION],
            'gone in autocommit' => [MysqlError::CR_SERVER_GONE_ERROR, false, MysqlRetryVerdict::LOST_AUTOCOMMIT],
            'lost in autocommit' => [MysqlError::CR_SERVER_LOST, false, MysqlRetryVerdict::LOST_AUTOCOMMIT],
            'deadlock with open tx' => [MysqlError::ER_LOCK_DEADLOCK, true, MysqlRetryVerdict::DEADLOCK],
            'deadlock without tx' => [MysqlError::ER_LOCK_DEADLOCK, false, MysqlRetryVerdict::DEADLOCK],
            'lock wait timeout with open tx' => [MysqlError::ER_LOCK_WAIT_TIMEOUT, true, MysqlRetryVerdict::LOCK_WAIT_TIMEOUT],
            'lock wait timeout without tx' => [MysqlError::ER_LOCK_WAIT_TIMEOUT, false, MysqlRetryVerdict::LOCK_WAIT_TIMEOUT],
            'unknown sentinel (driverCode() ?? 0)' => [0, true, MysqlRetryVerdict::STATEMENT_FAILURE],
            'duplicate key' => [MysqlError::ER_DUP_ENTRY, false, MysqlRetryVerdict::STATEMENT_FAILURE],
            'syntax error' => [1146, true, MysqlRetryVerdict::STATEMENT_FAILURE],
        ];
    }

    #[DataProvider('classify_table')]
    public function test_the_table_classifies_every_coded_case(int $code, bool $txOpen, MysqlRetryVerdict $expected): void
    {
        self::assertSame($expected, MysqlRetryPolicy::classify($code, $txOpen));
    }

    /**
     * Incident 1.1, the crown pin of the whole design: a gone-away
     * failure inside an open transaction is NEVER replayed by the class.
     * Silent replay connected a fresh socket and executed the statement
     * outside the (now lost) transaction - a write that commits from the
     * application's point of view but belongs to no transaction at all.
     */
    public function test_the_lost_in_transaction_verdict_never_replays_the_statement(): void
    {
        $verdict = MysqlRetryVerdict::LOST_INSIDE_TRANSACTION;

        self::assertFalse($verdict->replaysStatement(), 'strict-ladder law: no silent replay behind a lost transaction');
        self::assertTrue($verdict->marksPoisoned(), 'the next commit must be refused, not silently re-run');
        self::assertTrue($verdict->rollsBackWholeTransaction(), 'the local bookkeeping rolls back before marking');
        self::assertFalse($verdict->transactionRemainsUsable(), 'a dead socket carries nothing forward');
    }

    /**
     * The autocommit twin: the landed arm IS the replay-verdict arm —
     * outside any transaction the ladder closes the socket and retries.
     * This pin does not count the retries: the once-only bound is pinned
     * consumer-side by the ladder loops themselves; the verdict class
     * answers only the replay-yes/no question.
     */
    public function test_the_lost_in_autocommit_verdict_is_replay_verdict(): void
    {
        $verdict = MysqlRetryVerdict::LOST_AUTOCOMMIT;

        self::assertTrue($verdict->replaysStatement(), 'the single autocommit retry is the landed arm');
        self::assertFalse($verdict->marksPoisoned(), 'no transaction existed to lose');
        self::assertFalse($verdict->rollsBackWholeTransaction(), 'nothing to roll back');
    }

    /**
     * Design §1.6b: 1213 kills the ENTIRE transaction server-side, so
     * the blanket rollback in the landed code is ALIGNED with reality -
     * the verdict keeps it, and the ladder callers (who re-issue their
     * whole transaction from scratch) stay safe.
     */
    public function test_the_deadlock_verdict_rolls_back_the_whole_transaction(): void
    {
        $verdict = MysqlRetryVerdict::DEADLOCK;

        self::assertTrue($verdict->rollsBackWholeTransaction(), 'MySQL already killed it; blanket rollback matches reality');
        self::assertFalse($verdict->transactionRemainsUsable(), 'a deadlocked transaction is gone server-side');
        self::assertFalse($verdict->replaysStatement(), 'retry is the caller ladder duty, not a class-level replay');
        self::assertFalse($verdict->marksPoisoned());
    }

    /**
     * Design §1.6 / §Q-A, the dual-recovery half: 1205 rolls back ONLY
     * the failed statement - the transaction stays OPEN server-side.
     * Blanket-rolling it (the landed behavior) discards committed-in-
     * spirit work; the surgical arm leaves the transaction usable.
     * This verdict is the policy expression of the design's recommended
     * shape (ii); the consumer rewiring stays an owner-gated Q-A wave.
     */
    public function test_the_lock_wait_timeout_verdict_leaves_the_transaction_usable(): void
    {
        $verdict = MysqlRetryVerdict::LOCK_WAIT_TIMEOUT;

        self::assertFalse($verdict->rollsBackWholeTransaction(), '1205 cost only the statement, not the transaction');
        self::assertTrue($verdict->transactionRemainsUsable(), 'the dual-recovery distinction against 1213');
        self::assertFalse($verdict->replaysStatement(), 'retry is the caller ladder duty, not a class-level replay');
        self::assertFalse($verdict->marksPoisoned(), 'the socket is alive and so is the transaction');
    }

    /**
     * The landed blanket else-arm: everything not classified above rolls
     * the transaction back and rethrows wrapped. Honest scope: only 1205
     * is proven transaction-alive server-side after failure, so the
     * other codes keep the landed behavior pending the Q-A decision.
     */
    public function test_the_statement_failure_verdict_is_the_landed_blanket(): void
    {
        $verdict = MysqlRetryVerdict::STATEMENT_FAILURE;

        self::assertTrue($verdict->rollsBackWholeTransaction());
        self::assertFalse($verdict->replaysStatement());
        self::assertFalse($verdict->marksPoisoned());
        self::assertFalse($verdict->transactionRemainsUsable());
    }

    /**
     * The refusal wording is a shared constant (SSOT for the message
     * consumers append to the SQL-prefixed PDOException text). Leading
     * space included: it is concatenated verbatim after the original
     * message by every consumer.
     */
    public function test_the_lost_in_transaction_suffix_is_the_landed_wording(): void
    {
        self::assertSame(
            ' (connection lost inside a transaction; not retried)',
            MysqlRetryPolicy::LOST_IN_TX_SUFFIX
        );
        self::assertSame(MysqlRetryPolicy::LOST_IN_TX_SUFFIX, MysqlRetryVerdict::LOST_INSIDE_TRANSACTION->lostInTransactionSuffix());
        self::assertNull(MysqlRetryVerdict::LOST_AUTOCOMMIT->lostInTransactionSuffix());
        self::assertNull(MysqlRetryVerdict::DEADLOCK->lostInTransactionSuffix());
        self::assertNull(MysqlRetryVerdict::LOCK_WAIT_TIMEOUT->lostInTransactionSuffix());
        self::assertNull(MysqlRetryVerdict::STATEMENT_FAILURE->lostInTransactionSuffix());
    }

    /**
     * Cross-check of the two EXECUTED gone codes against the shape the
     * estate actually logged: the in-transaction leg carries the refusal
     * suffix and the poison mark; the autocommit leg carries neither.
     */
    public function test_the_two_gone_arms_differ_only_in_transaction_liveness(): void
    {
        self::assertSame(
            MysqlRetryVerdict::LOST_INSIDE_TRANSACTION,
            MysqlRetryPolicy::classify(MysqlError::CR_SERVER_LOST, true)
        );
        self::assertSame(
            MysqlRetryVerdict::LOST_AUTOCOMMIT,
            MysqlRetryPolicy::classify(MysqlError::CR_SERVER_LOST, false)
        );
        self::assertTrue(MysqlRetryPolicy::classify(MysqlError::CR_SERVER_GONE_ERROR, true)->marksPoisoned());
        self::assertFalse(MysqlRetryPolicy::classify(MysqlError::CR_SERVER_GONE_ERROR, false)->marksPoisoned());
    }
}
