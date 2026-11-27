<?php

/**
 * Tx End.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Database;

/**
 * The verdict a transaction-mutex claim hands back to the caller.
 *
 * Both estate connections (server, hub) answer "end my transaction?"
 * with a small ladder of early exits before touching the vendor layer.
 * The ladder was prose in the TXMUTEX-A design dossier and duplicated
 * in two repos; this enum is the Option-B extraction of it into one
 * executable vocabulary, so the shared
 * {@see MysqlTxLockState::claimCommit()} /
 * {@see MysqlTxLockState::claimRollback()} decision and the caller's
 * vendor action can never drift apart again.
 *
 * The caller mapping is total and mechanical:
 *  - {@see self::INERT}          → return true, run nothing, change nothing
 *  - {@see self::POISON_REFUSED} → throw the machine's refusal wording
 *  - {@see self::PASS_THROUGH}   → delegate to the vendor end directly
 *  - {@see self::NESTED}         → run the vendor end; on truthy, decrement()
 *  - {@see self::CLOSE}          → run the vendor end, then handOff()
 *
 * @see MysqlTxLockState
 *
 * @since 0.58.0
 */
enum TxEnd
{
    /**
     * The caller is not the lease holder: the landed guard sits ABOVE
     * every other check (including the poison check), so a stranger's
     * commit or rollback is inert success — it neither ends the holder's
     * transaction nor consumes its one-shot refusal budget.
     */
    case INERT;

    /**
     * The holder tried to commit a poisoned connection. The claim has
     * ALREADY consumed the mark (exactly one denial per incident); the
     * holder and its open flag are deliberately left in place because
     * the landed refusal does not release — the caller's catch-rollback
     * completes the ceremony.
     */
    case POISON_REFUSED;

    /**
     * No coroutine context (CLI, or a vendor-threaded caller): the
     * mutex laws do not apply and the caller delegates to the vendor
     * end. For rollback this arm also consumes a poison mark silently —
     * an explicit rollback IS the fresh start.
     */
    case PASS_THROUGH;

    /**
     * The landed nesting>1 decrement arms, preserved as an explicit
     * verdict. Under the machine's nested-begin refusal this arm is
     * unreachable through public API (the flag is a 0/1 law); it exists
     * so the callers keep their depth-check guards honest rather than
     * pretending the old code paths never existed.
     */
    case NESTED;

    /**
     * The outermost end of the outermost transaction: the caller runs
     * the vendor commit/rollback and hands the token off
     * ({@see MysqlTxLockState::handOff()}). The claim has already moved
     * the nesting flag to zero so the hand-off can be observed as clean.
     */
    case CLOSE;
}
