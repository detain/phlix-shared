<?php

/**
 * Mysql Retry Verdict.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Database;

/**
 * One classified statement failure, expressed as four independent verbs.
 *
 * The landed execute() ladders in server and hub hard-code what to do
 * per error code inside the catch block; the machine form splits the
 * decision ({@see MysqlRetryPolicy::classify()}) from the four actions
 * a consumer may take, so each action is separately testable and the
 * strict-ladder laws cannot be "conveniently" skipped at one call site
 * while honored at another.
 *
 * Provenance of each shape:
 *  - the two GONE arms: incident 1.1 — silent replay of a statement
 *    that failed inside an open transaction lands the write on a fresh
 *    socket outside any transaction ("B landed, A lost"). Hence
 *    {@see self::LOST_INSIDE_TRANSACTION} never replays and always
 *    poisons; {@see self::LOST_AUTOCOMMIT} replays exactly once.
 *  - DEADLOCK: design §1.6b — 1213 kills the ENTIRE transaction
 *    server-side, so the blanket local rollback is aligned with reality,
 *    and the retry-the-transaction duty belongs to the caller's ladder.
 *  - LOCK_WAIT_TIMEOUT: design §1.6/§Q-A — 1205 cost only the failed
 *    statement; the transaction stays OPEN server-side. The landed
 *    blanket rollback was wrong for it; this verdict is the surgical
 *    arm the design recommends. Consumer rewiring remains the owner-
 *    gated Q-A wave — the machine models the truth, the wave applies it.
 *  - STATEMENT_FAILURE: the landed blanket else-arm (roll back the
 *    transaction, rethrow wrapped). Honest scope: only 1205 is proven
 *    transaction-alive server-side after failure, so everything else,
 *    including unknown codes (driverCode() ?? 0), keeps landed behavior.
 *
 * @see MysqlRetryPolicy
 *
 * @since 0.58.0
 */
enum MysqlRetryVerdict
{
    /** The statement died with a live transaction on a dead socket. */
    case LOST_INSIDE_TRANSACTION;

    /** The statement died on a dead socket with no transaction open. */
    case LOST_AUTOCOMMIT;

    /** 1213: MySQL chose this transaction as the deadlock victim. */
    case DEADLOCK;

    /** 1205: this statement lost a lock wait; the transaction stands. */
    case LOCK_WAIT_TIMEOUT;

    /** Anything else, including unclassified driver codes. */
    case STATEMENT_FAILURE;

    /**
     * May the CLASS silently re-run the failed statement? Only the
     * autocommit gone-arm: one reconnect-retry is the landed arm and it
     * touches no transaction. Everything else either refuses (lost in
     * tx) or defers the retry to the caller's ladder (deadlock, lock
     * wait, statement failure) — a class-level replay is exactly the
     * incident-1.1 vector.
     */
    public function replaysStatement(): bool
    {
        return $this === self::LOST_AUTOCOMMIT;
    }

    /**
     * Does handling this failure end the WHOLE transaction locally?
     * True for the lost-in-tx arm (the landed order runs the internal
     * rollback before marking), for the deadlock (server already
     * destroyed it), and for the landed blanket. False for the lock-wait
     * (surgical arm — the transaction is still usable server-side) and
     * for the autocommit retry (nothing to end).
     */
    public function rollsBackWholeTransaction(): bool
    {
        return match ($this) {
            self::LOST_INSIDE_TRANSACTION, self::DEADLOCK, self::STATEMENT_FAILURE => true,
            self::LOST_AUTOCOMMIT, self::LOCK_WAIT_TIMEOUT => false,
        };
    }

    /**
     * Must the next commit be refused? Only the lost-in-tx arm poisons:
     * the transaction the application believes it owns is gone.
     */
    public function marksPoisoned(): bool
    {
        return $this === self::LOST_INSIDE_TRANSACTION;
    }

    /**
     * Is the current transaction still usable after handling? The
     * dual-recovery distinction against the deadlock verdict: 1205 cost
     * a statement, 1213 cost the transaction. Callers choosing "retry
     * the whole thing" must know which they are standing in.
     */
    public function transactionRemainsUsable(): bool
    {
        return $this === self::LOCK_WAIT_TIMEOUT;
    }

    /**
     * The refusal wording appended to the rethrown message by consumers
     * handling this verdict, or null when no suffix applies. Leading
     * space included: the landed code concatenates it directly after
     * the original exception text.
     */
    public function lostInTransactionSuffix(): ?string
    {
        return $this === self::LOST_INSIDE_TRANSACTION ? MysqlRetryPolicy::LOST_IN_TX_SUFFIX : null;
    }
}
