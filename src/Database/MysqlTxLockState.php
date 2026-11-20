<?php

/**
 * Mysql Tx Lock State.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Database;

use InvalidArgumentException;
use LogicException;
use PDOException;

/**
 * The transaction-mutex state machine, extracted from the estate union.
 *
 * Under Swoole a pooled MySQL connection is shared by coroutines, so a
 * transaction is a LEASE: exactly one coroutine may hold it, the lease
 * must survive cancellation and error cleanup without forking, and a
 * stranger must never be able to end it. The server and hub each landed
 * the same hardening independently (TXMUTEX-A arms A1-A7); this class
 * is the Option-B single source of truth for the mechanics they share,
 * so the ordering laws stop living only in two copies of prose.
 *
 * The laws encoded here, each pinned by the characterization suite:
 *  - Cancelled acquire, no contamination (incident 1.3, hub): the park
 *    decision runs BEFORE any bookkeeping is touched; a cancelled
 *    waiter neither installs itself as holder nor mints a token, so the
 *    next waiter wakes to a clean ledger instead of a spurious lease.
 *  - Stranger-inert ends (incident 1.2, hub): commit/rollback by a
 *    non-holder is inert success, ABOVE the poison check — a stranger
 *    cannot deplete the holder's one-shot refusal budget.
 *  - Hand-off ordering (design §1.5): releasing clears the holder
 *    BEFORE the token push is issued, and the push is the LAST write to
 *    the shared ledger, because Swoole resumes a parked waiter
 *    synchronously inside push(). Replacing or re-walking the ledger
 *    after the push forks the mutex.
 *  - Poison is one-shot (incident 1.1, server): a transaction lost to a
 *    dropped connection denies exactly ONE commit; the mark is consumed
 *    by the refusal, cleared by begin/rollback paths, and flattened
 *    wholesale on reconnect because a fresh socket cannot carry the
 *    previous socket's transaction.
 *  - The query fast-path needs BOTH terms (incident 1.4, server cure
 *    A4): {@see self::holdingTransactionFor()} is the holder check that
 *    the landed gate had to grow.
 *
 * What this class deliberately is NOT: it performs no I/O and knows no
 * driver. The mutex token itself stays behind injected callables — the
 * park/pop and push closures come from the consumer, so no Swoole
 * Channel type leaks into the shared public surface (the estate design
 * constraint: shared CI lacks swoole). Vendor calls (begin/commit/
 * rollBack on the underlying connection) belong to the consumer, which
 * keeps its extension and repo-specific arms and delegates the
 * bookkeeping here.
 *
 * @see TxEnd            the verdicts the end-claims return
 * @see MysqlRetryPolicy the sibling decision table for the error path
 */
final class MysqlTxLockState
{
    /**
     * The sentinel holder id meaning "nobody holds the lease"; any real
     * coroutine id is >= 0, mirroring the landed transLockHolder=-1.
     */
    public const FREE_HOLDER = -1;

    /**
     * Byte-identical to the vendor text every estate caller already
     * catches: a nested begin by the current holder must refuse with
     * exactly this message, not a new dialect.
     */
    public const NESTED_BEGIN_MESSAGE = 'There is already an active transaction';

    /**
     * Byte-identical between the landed server and hub arms: the park
     * returned false (the coroutine was cancelled while waiting) and the
     * acquire must say so precisely, before installing anything.
     */
    public const CANCELLED_ACQUIRE_MESSAGE = 'Could not acquire the transaction mutex: '
        . 'the coroutine was cancelled while waiting';

    /**
     * The one-shot refusal wording for committing through a connection
     * whose transaction was lost mid-flight. Consumers throw this text;
     * it is pinned shared-side so both repos stay word-for-word honest.
     */
    public const POISONED_COMMIT_MESSAGE = 'Transaction was lost when the connection dropped; '
        . 'refusing to commit.';

    private int $holder = self::FREE_HOLDER;

    /**
     * The landed classes keep a nesting counter with decrement arms for
     * depth > 1; under the refusal law the flag is effectively 0/1. The
     * machine preserves both readings so consumer guards stay reachable.
     */
    private int $nesting = 0;

    private bool $poisoned = false;

    /**
     * The current lease holder, or {@see self::FREE_HOLDER}.
     */
    public function holder(): int
    {
        return $this->holder;
    }

    /**
     * How many transaction ends the ledger still owes (0 or, under the
     * legacy guard shape, more).
     */
    public function nestingLevel(): int
    {
        return $this->nesting;
    }

    /**
     * Whether the next commit attempt must be refused (lost transaction).
     */
    public function isPoisoned(): bool
    {
        return $this->poisoned;
    }

    /**
     * The A4 fast-path predicate: does THIS coroutine hold an OPEN
     * transaction on this connection?
     *
     * Both terms are load-bearing. Holder identity alone let a foreign
     * coroutine short-circuit onto the direct query path while the
     * holder's transaction was open, and the write landed inside it and
     * vanished on rollback; nesting alone let the holder's own
     * install-window statements bypass the per-query lock.
     */
    public function holdingTransactionFor(int $cid): bool
    {
        return $this->nesting > 0 && $this->holder === $cid;
    }

    /**
     * Take (or re-take) the lease.
     *
     * The three arms mirror the landed beginTrans order:
     *  - already-holder + open transaction: the nested-begin refusal,
     *    carrying the vendor wording (the caller never reaches the
     *    vendor with a real nested begin, and the vendor would throw
     *    this same text — equivalence, not imitation);
     *  - already-holder + not-yet-open: already owns the token, nothing
     *    to park for — proceed to the vendor begin and
     *    {@see self::markOpen()} on success;
     *  - stranger: park on the token FIRST. A false answer is a
     *    cancelled wait and throws BEFORE any bookkeeping (no holder
     *    install, no nesting movement, no token emitted). Only a true
     *    answer installs the holder identity.
     *
     * @param callable(): bool $park blocks for one mutex token; returns
     *                             false when the wait was cancelled
     *
     * @throws InvalidArgumentException on a non-coroutine id (callers
     *                                  with cid < 0 bypass the mutex)
     * @throws PDOException             on nested-begin and cancellation
     *                                  refusals, message-only for
     *                                  catch-shape parity with the
     *                                  landed arms
     */
    public function acquire(int $cid, callable $park): void
    {
        if ($cid < 0) {
            throw new InvalidArgumentException(
                'acquire() requires a coroutine id; non-coroutine callers delegate to the vendor begin directly'
            );
        }

        if ($this->holder === $cid) {
            if ($this->nesting > 0) {
                throw new PDOException(self::NESTED_BEGIN_MESSAGE);
            }

            return;
        }

        if (!$park()) {
            throw new PDOException(self::CANCELLED_ACQUIRE_MESSAGE);
        }

        $this->holder = $cid;
    }

    /**
     * Record that the vendor begin succeeded for the current lease.
     *
     * Opening a transaction is exactly-once per lease (the nested-begin
     * refusal makes a second open caller misuse), and opening clears a
     * stale poison mark: the landed beginTrans resets txPoisoned on
     * every success path, so the fresh transaction starts with a clean
     * refusal budget.
     *
     * @throws LogicException on open-without-lease or open-on-open
     */
    public function markOpen(): void
    {
        if ($this->holder === self::FREE_HOLDER) {
            throw new LogicException('markOpen() requires an acquired lease');
        }

        if ($this->nesting > 0) {
            throw new LogicException('markOpen() called twice for one lease');
        }

        $this->nesting = 1;
        $this->poisoned = false;
    }

    /**
     * Release a lease that was acquired but whose vendor begin never
     * succeeded (the catch-Throwable and falsey-parent cleanup arms).
     *
     * Guarded by holder identity: if the ledger already moved on (the
     * hand-off happened while the error path was in flight) the cleanup
     * must stay inert — clobbering a new owner's lease is exactly the
     * fork the ordering laws exist to prevent. Returns whether the
     * caller's release actually took effect.
     *
     * Provenance note: there is no server/hub divergence to reconcile
     * on a falsey-parent fold — both landed arms clear poison there
     * (the server's fold releases and then falls through to the holder
     * path's unconditional "poison is history" clear, whose own comment
     * names the hub as the mirror; the hub's Q-C fold clears before it
     * releases). The machine keeps that agreement inside one call: a
     * release that left a mark with no transaction behind it would
     * strand the next holder's refusal budget on a dead lease.
     *
     * The catch-Throwable cleanup arm is a different landed shape in
     * BOTH repos: the server's releases with `releaseTransLock()` (free
     * the holder, push the token) and the hub's with
     * `inTransaction = false; releaseQueryLock()` — and neither touches
     * the poison mark, because a throw from a BEGIN that never opened a
     * transaction must not consume a refusal budget some earlier scope
     * genuinely earned. {@see self::$clearPoison} carries that split:
     * the falsy fold keeps the clearing default, the throw arms pass
     * FALSE to stay byte-faithful to both landed sources.
     *
     * @param callable(): void $push        hands the token to the next waiter
     * @param bool             $clearPoison whether the release also consumes
     *                                      the poison mark (TRUE for the
     *                                      falsey-parent fold; FALSE for the
     *                                      begin-threw cleanup arm)
     */
    public function abandonIfHeld(int $cid, callable $push, bool $clearPoison = true): bool
    {
        if ($this->holder !== $cid) {
            return false;
        }

        if ($clearPoison) {
            $this->poisoned = false;
        }

        $this->holder = self::FREE_HOLDER;
        $push();

        return true;
    }

    /**
     * Claim the right to COMMIT as $cid, with the landed ladder order.
     *
     *  1. stranger → INERT (above everything, above the poison check);
     *  2. poisoned → consume the mark, POISON_REFUSED (the refusal does
     *     not release — holder and nesting stand for the caller's
     *     catch-rollback);
     *  3. cid < 0  → PASS_THROUGH (CLI: no mutex, no ledger);
     *  4. depth > 1→ NESTED (caller ends one level, decrements on truthy);
     *  5. otherwise the flag goes to 0 and the caller owes CLOSE: vendor
     *     end, then {@see self::handOff()}.
     *
     * Why the flag moves BEFORE the vendor call: the hand-off must be
     * observable as clean (§1.5), and a vendor throw still leaves the
     * finally-block hand-off well-formed.
     */
    public function claimCommit(int $cid): TxEnd
    {
        if ($cid >= 0 && $this->holder !== $cid) {
            return TxEnd::INERT;
        }

        if ($this->poisoned) {
            $this->poisoned = false;

            return TxEnd::POISON_REFUSED;
        }

        if ($cid < 0) {
            return TxEnd::PASS_THROUGH;
        }

        if ($this->nesting > 1) {
            return TxEnd::NESTED;
        }

        $this->nesting = 0;

        return TxEnd::CLOSE;
    }

    /**
     * Claim the right to ROLL BACK as $cid, with the landed ladder order.
     *
     *  1. cid < 0 → PASS_THROUGH, and the CLI rollback consumes any
     *     poison WITHOUT refusal — an explicit rollback is the fresh
     *     start (contrast the commit ladder, where refusal sits above
     *     the passthrough arm);
     *  2. stranger → INERT, touching nothing;
     *  3. depth > 1 → NESTED (same decrement duty as the commit arm);
     *  4. otherwise flag to 0 and the caller owes CLOSE.
     *
     * The landed rollback runs the vendor rollBack inside a finally that
     * clears poison and hands off; the machine keeps both facts: the
     * claim moves the counter, {@see self::handOff()} clears the rest.
     */
    public function claimRollback(int $cid): TxEnd
    {
        if ($cid < 0) {
            $this->poisoned = false;

            return TxEnd::PASS_THROUGH;
        }

        if ($this->holder !== $cid) {
            return TxEnd::INERT;
        }

        if ($this->nesting > 1) {
            return TxEnd::NESTED;
        }

        $this->nesting = 0;

        return TxEnd::CLOSE;
    }

    /**
     * Bookkeeping for a successful NESTED-arm end: one level down, never
     * below the flag line. Below depth 1 the decrement is a no-op — the
     * 0/1 law must not be driven negative by a stale guard arm.
     */
    public function decrement(): void
    {
        if ($this->nesting > 1) {
            $this->nesting--;
        }
    }

    /**
     * The hand-off: end the lease cleanly.
     *
     * Order is the §1.5 invariant in code form — poison cleared, holder
     * set free, and only then the token pushed. Because Swoole resumes a
     * parked waiter synchronously inside push(), anything visible after
     * the push is visible to the new holder at wake-up; the push is
     * therefore the LAST write this method (and, by law, the landed
     * consumers) performs on the shared ledger.
     *
     * @param callable(): void $push hands the token to the next waiter
     */
    public function handOff(callable $push): void
    {
        $this->poisoned = false;
        $this->holder = self::FREE_HOLDER;
        $push();
    }

    /**
     * Mark the connection's transaction lost. Idempotent: the landed
     * execute arm sets the flag unconditionally after its internal
     * rollback has run, so marking twice is a no-op, not an error.
     */
    public function markPoisoned(): void
    {
        $this->poisoned = true;
    }

    /**
     * Clear the mark on the CLI-begin path: the landed beginTrans clears
     * txPoisoned after a successful vendor begin with no coroutine (an
     * explicit fresh start over the same socket).
     */
    public function clearPoison(): void
    {
        $this->poisoned = false;
    }

    /**
     * Flatten the whole ledger for a socket replacement: a fresh
     * connection cannot be mid-transaction on the old one, and the pool
     * must not hand a dirty lease to whoever borrows next.
     *
     * No token is pushed here — the channel belongs to the retired
     * socket's consumer logic, not to this ledger. A consumer that
     * replaces a channel must ensure no waiter is stranded; the
     * landed closeConnection arms leave parked waiters to the pool
     * layer, which this machine deliberately does not model.
     */
    public function clearOnReconnect(): void
    {
        $this->holder = self::FREE_HOLDER;
        $this->nesting = 0;
        $this->poisoned = false;
    }
}
