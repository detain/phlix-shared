<?php

/**
 * Tx Lease.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Database;

use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * One coroutine's binding to the transaction-mutex ledger.
 *
 * {@see MysqlTxLockState} is the whole ledger: any coroutine can ask
 * it anything, and every verb takes the caller's identity as an
 * argument. That is the right shape for the ledger itself, but the
 * consumers' ceremonies always run from inside exactly one coroutine
 * against exactly one ledger, passing the same four values — state,
 * coroutine id, park seam, push seam — to verb after verb. This class
 * binds those four once and hands back verbs that cannot be pointed at
 * the wrong coroutine, which is the drift the TXMUTEX-A incidents were:
 * a ceremony arguing with itself about which id to pass.
 *
 * Scope is exactly the landed reality, and nothing more:
 *  - bound reads and delegates for every {@see MysqlTxLockState} verb,
 *    semantics untouched — the ledger laws (stranger-inert ends above
 *    the poison check, one-shot poison, clear-holder-before-push,
 *    A-1/A-2 abandon floors) stay owned by the machine and stay pinned
 *    by its characterization suite;
 *  - one unified ceremony, {@see self::begin()}: the server and the
 *    hub landed the same acquire/catch/fold/open/clear sequence (the
 *    retrofit's differentials proved it zero-delta on both sides), so
 *    it has a single faithful home;
 *  - NO unified commit/rollback ceremony. The two consumers
 *    deliberately diverge there (the server's poison refusal keeps the
 *    lease, the hub's releases via its caller-side finally), so the
 *    lease only exposes the granular claim verbs and hand-off and lets
 *    each repo keep its own end sequence.
 *
 * The lease carries no state of its own beyond the binding: it is a
 * pass-through with an identity, safe to construct per ceremony, and
 * never a second source of truth.
 *
 * @see MysqlTxLockState
 * @see TxEnd
 */
final class TxLease
{
    /**
     * The bound park seam: blocks for one mutex token, false on cancel.
     * (Callable seams bind as Closure — property types cannot be callable.)
     *
     * @var Closure(): bool
     */
    private readonly Closure $park;

    /**
     * The bound push seam: returns the token when the lease frees.
     *
     * @var Closure(): void
     */
    private readonly Closure $push;

    /**
     * @param MysqlTxLockState $state the ledger this ceremony runs against
     * @param int              $cid   the coroutine id owning the ceremony; must be
     *                                non-negative — a non-coroutine caller (CLI) has
     *                                no lease to bind and delegates to the vendor
     *                                layer directly, exactly as the landed consumers
     *                                branch before ever touching the machine
     * @param callable():bool  $park  blocks for one mutex token, false on cancel
     * @param callable():void  $push  returns the token when the lease frees
     *
     * @throws InvalidArgumentException when $cid is not a coroutine id
     */
    public function __construct(
        private readonly MysqlTxLockState $state,
        private readonly int $cid,
        callable $park,
        callable $push,
    ) {
        if ($cid < 0) {
            throw new InvalidArgumentException(
                'TxLease requires a coroutine id; non-coroutine callers delegate to the vendor directly'
            );
        }

        $this->park = $park(...);
        $this->push = $push(...);
    }

    /**
     * The ceremony's coroutine id, as bound at construction.
     */
    public function coroutineId(): int
    {
        return $this->cid;
    }

    /**
     * Current holder of the ledger (FREE_HOLDER when unclaimed).
     */
    public function holder(): int
    {
        return $this->state->holder();
    }

    /**
     * Nesting counter as the ledger sees it.
     */
    public function nestingLevel(): int
    {
        return $this->state->nestingLevel();
    }

    /**
     * Whether the one-shot poison mark is currently set on the ledger.
     */
    public function isPoisoned(): bool
    {
        return $this->state->isPoisoned();
    }

    /**
     * Whether this ceremony's coroutine holds an OPEN transaction
     * (delegates holdingTransactionFor() with the bound id — both
     * terms required, holder identity alone is not holding).
     */
    public function holds(): bool
    {
        return $this->state->holdingTransactionFor($this->cid);
    }

    /**
     * Whether this ceremony's coroutine is the ledger's holder, open
     * or not. Reentrant callers gate on this BEFORE {@see self::begin()}
     * to keep their own vendor-throw identity for nested begins (the
     * landed D-2 posture); an unguarded begin while this reads true
     * with an open lease surfaces the machine's nested-begin wording.
     */
    public function isHolder(): bool
    {
        return $this->state->holder() === $this->cid;
    }

    /**
     * Take the mutex for this coroutine (cancel-checked install,
     * silent install-window proceed, nested-begin refusal — all the
     * machine's laws, unchanged).
     *
     * @throws \PDOException on cancelled park or nested begin
     */
    public function acquire(): void
    {
        $this->state->acquire($this->cid, $this->park);
    }

    /**
     * Record this ceremony's vendor begin success (exactly-once,
     * clears the stale poison budget — machine laws unchanged).
     */
    public function markOpen(): void
    {
        $this->state->markOpen();
    }

    /**
     * Lower the nesting counter after a successful nested end
     * (only ever fires above one open level, as in the machine).
     */
    public function decrement(): void
    {
        $this->state->decrement();
    }

    /**
     * Abandon this ceremony's lease if it still owns the ledger:
     * floors the transaction flag to the never-raised value with the
     * holder (A-2), hands the token back, and by default clears the
     * poison mark (A-1). A non-holder abandon is inert and returns
     * false.
     */
    public function abandon(bool $clearPoison = true): bool
    {
        return $this->state->abandonIfHeld($this->cid, $this->push, $clearPoison);
    }

    /**
     * Claim a transaction end (commit) for this coroutine.
     */
    public function claimCommit(): TxEnd
    {
        return $this->state->claimCommit($this->cid);
    }

    /**
     * Claim a transaction end (rollback) for this coroutine.
     */
    public function claimRollback(): TxEnd
    {
        return $this->state->claimRollback($this->cid);
    }

    /**
     * Release this ceremony's hold on the ledger at transaction end:
     * clear poison, free the holder, push the token LAST (design
     * §1.5 ordering law, unchanged). The release binds this lease's
     * coroutine identity (v0.59 bank row 1): a call whose holder has
     * moved on is FULLY INERT and returns FALSE — the ledger and the
     * token stand; TRUE whenever the release runs.
     */
    public function handOff(): bool
    {
        return $this->state->handOff($this->push, $this->cid);
    }

    /**
     * Set the one-shot poison mark (idempotent, machine law).
     */
    public function markPoisoned(): void
    {
        $this->state->markPoisoned();
    }

    /**
     * Consume the poison mark without ending anything.
     */
    public function clearPoison(): void
    {
        $this->state->clearPoison();
    }

    /**
     * Flatten the whole ledger after a reconnect (no token pushed —
     * the socket that carried the waiters is gone, machine law).
     */
    public function clearOnReconnect(): void
    {
        $this->state->clearOnReconnect();
    }

    /**
     * Run the whole begin ceremony for this coroutine.
     *
     * This is the acquire/start/fold/open sequence the server and the
     * hub both landed (differentially proven zero-delta by the
     * TXMUTEX-B retrofit), in one faithful home:
     *  1. acquire() — a cancelled park throws BEFORE any bookkeeping,
     *     a nested begin on an open lease throws the machine wording
     *     (guarded callers check isHolder() first and never arrive);
     *  2. start — the vendor begin, wrapped;
     *  3. a throw releases the lease WITHOUT consuming the poison
     *     mark (the A-1/clearPoison:false arm both consumers landed in
     *     their catch-Throwable cleanup: a failed BEGIN must not spend
     *     a refusal budget an earlier scope earned) and rethrows the
     *     original Throwable identity;
     *  4. truthy opens the lease; falsy folds through abandon() with
     *     the clearing default (A-1: the vendor said no, so any mark
     *     this scope rode in on is stale and the budget is reset);
     *  5. the unconditional clearPoison() fall-through mirrors the
     *     landed consumers' tail line. On every reachable arm it is
     *     already true of the ledger (markOpen resets the budget, the
     *     fold clears it), and it is kept because BOTH landed sources
     *     carry it — the ceremony's post-condition ("begin ends with a
     *     clean refusal budget") stays expressed at the ceremony
     *     level, not only inferred from the arms.
     *
     * @param callable():bool $startTransaction runs the vendor begin;
     *                                          truthy means the transaction is open
     *
     * @return bool the vendor begin's result, unchanged
     *
     * @throws \PDOException from the acquire law violations
     * @throws Throwable the exact original from a failed start
     */
    public function begin(callable $startTransaction): bool
    {
        $this->acquire();

        try {
            $started = $startTransaction();
        } catch (Throwable $thrown) {
            $this->abandon(false);

            throw $thrown;
        }

        if ($started) {
            $this->markOpen();
        } else {
            $this->abandon();
        }

        $this->clearPoison();

        return $started;
    }
}
