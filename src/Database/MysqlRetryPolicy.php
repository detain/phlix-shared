<?php

/**
 * Mysql Retry Policy.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Database;

/**
 * The single execute() decision table for MySQL statement failures.
 *
 * Two facts fully determine the landed ladder's control flow: the
 * driver error code (as classified from the exception's errorInfo by
 * {@see MysqlError::driverCode()}) and whether a transaction was open
 * when the statement died. Everything else — the SQL prefix, the wrap
 * fidelity, the connection close — is execution on the consumer side.
 * One table, both repos, no drift: the v0.57 SSOT-unification ask.
 *
 * Documented deviation from the original design sketch: it listed
 * "poisoned" as a third input to the decision table; no branch consumes
 * it. Marking on
 * the gone-in-transaction arm is idempotent whether or not a previous
 * incident already poisoned the connection, and the one-shot consumption
 * of the mark is owned by the state machine
 * ({@see MysqlTxLockState::claimCommit()}), not by classification. A
 * parameter no arm reads would be a promise the code cannot keep; the
 * law of intentional naming prefers leaving it out and saying so.
 *
 * @see MysqlRetryVerdict the actions the verdict licenses
 * @see MysqlTxLockState  the ledger the refusal budget lives in
 */
final class MysqlRetryPolicy
{
    /**
     * The landed suffix appended verbatim to the rethrown message of a
     * statement lost inside a transaction. The leading space is part of
     * the wording: consumers concatenate after the original exception
     * text, so the space keeps both halves one sentence.
     */
    public const LOST_IN_TX_SUFFIX = ' (connection lost inside a transaction; not retried)';

    private function __construct()
    {
    }

    /**
     * Classify one statement failure into its recovery verdict.
     *
     * @param int  $driverCode the numeric driver code, or 0 for "unknown"
     *                         (the landed `driverCode($e->errorInfo) ?? 0`
     *                         sentinel, which falls into the blanket arm)
     * @param bool $txOpen     whether a transaction was open when the
     *                         statement died — the load-bearing term that
     *                         separates the poison-marking gone arm from
     *                         the single-retry gone arm
     */
    public static function classify(int $driverCode, bool $txOpen): MysqlRetryVerdict
    {
        if ($driverCode === MysqlError::CR_SERVER_GONE_ERROR || $driverCode === MysqlError::CR_SERVER_LOST) {
            return $txOpen ? MysqlRetryVerdict::LOST_INSIDE_TRANSACTION : MysqlRetryVerdict::LOST_AUTOCOMMIT;
        }

        if ($driverCode === MysqlError::ER_LOCK_DEADLOCK) {
            return MysqlRetryVerdict::DEADLOCK;
        }

        if ($driverCode === MysqlError::ER_LOCK_WAIT_TIMEOUT) {
            return MysqlRetryVerdict::LOCK_WAIT_TIMEOUT;
        }

        return MysqlRetryVerdict::STATEMENT_FAILURE;
    }
}
