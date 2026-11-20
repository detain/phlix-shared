<?php

/**
 * Mysql Tx Lock State.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Database;

use PDOException;
use Phlix\Shared\Database\MysqlTxLockState;
use Phlix\Shared\Database\TxEnd;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Characterization suite for the transaction-mutex state machine.
 *
 * Each test pins a behavior that was EXECUTED as a real failure in the
 * estate before the TXMUTEX-A arms cured it (design dossier
 * DESIGN-TX-MUTEX.md §1, incidents 1.1-1.4), or a sequencing invariant
 * every landed cure silently rests on (§1.5 hand-off). The machine is
 * the Option-B extraction of the server/hub union (owner ruling 2c:
 * the SSOT rides v0.57 as a charter item); these tests are the reason
 * the extraction is safe, so they read as incident regressions rather
 * than unit happy-paths.
 *
 * Provenance of the pinned orders, in one line per incident:
 *  - 1.1 server: gone-away mid-transaction retried silently and the
 *    commit raced ahead of a lost transaction — policy leg is
 *    {@see MysqlRetryPolicyTest}; the ledger leg here is poison
 *    marking, one-shot refusal, and reconnect flattening.
 *  - 1.2 hub: a stranger coroutine's commit/rollback tore down the
 *    holder's transaction — the Inert arms below.
 *  - 1.3 hub: an unchecked channel pop handed a cancelled coroutine a
 *    bogus token whose cleanup poisoned the next waiter — the
 *    cancel-before-install pin.
 *  - 1.4 server: the query fast-path lacked the holder term and let a
 *    foreign write land inside the holder's transaction — the
 *    holdingTransactionFor() truth table.
 *
 * @see MysqlTxLockState
 * @see TxEnd
 */
#[CoversClass(MysqlTxLockState::class)]
#[CoversClass(TxEnd::class)]
final class MysqlTxLockStateTest extends TestCase
{
    /**
     * A released token observed at push time must find the ledger clean:
     * §1.5 makes "holder cleared before push" the invariant that every
     * landed "rollback in catch is inert" argument rests on, because
     * Swoole resumes a parked waiter synchronously inside push(). This
     * fake records what a waiter would see the instant it wakes.
     */
    private function fakeChannel(MysqlTxLockState $state, int $tokens = 1): FakeMutexChannel
    {
        return new FakeMutexChannel($state, $tokens);
    }

    public function test_the_state_machine_starts_free_and_clean(): void
    {
        $state = new MysqlTxLockState();

        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertSame(0, $state->nestingLevel());
        self::assertFalse($state->isPoisoned());
        self::assertFalse($state->holdingTransactionFor(1));
    }

    /**
     * Incident 1.3 shape (hub): the pop decision must happen BEFORE any
     * bookkeeping is touched — a cancelled acquirer leaves nothing behind
     * and never emits a token. A spurious push would hand the next waiter
     * a bogus lease on top of the real holder's still-open transaction
     * ("contagion" in the design dossier).
     */
    public function test_a_cancelled_acquire_refuses_before_touching_the_ledger(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);

        $state->acquire(9, $channel->park(...));
        $state->markOpen();

        // Same machine, cancelled waiter: zero tokens, park() answers false.
        $starved = $this->fakeChannel($state, 0);
        try {
            $state->acquire(5, $starved->park(...));
            self::fail('a cancelled park must refuse the acquire');
        } catch (PDOException $e) {
            self::assertSame(
                MysqlTxLockState::CANCELLED_ACQUIRE_MESSAGE,
                $e->getMessage(),
                'the cancellation wording is byte-identical to the landed server and hub arms'
            );
        }

        self::assertSame(9, $state->holder(), 'the cancelled waiter must not install itself');
        self::assertSame(1, $state->nestingLevel(), 'the holder transaction must be untouched');
        self::assertSame(0, $starved->pushCount, 'a refusal on the cancel path never emits a token');
        self::assertFalse($state->isPoisoned(), 'the refusal itself must not poison anything');
    }

    /**
     * Recovery half of incident 1.3: after the real holder finishes and
     * hands off, the next waiter acquires cleanly on the returned token.
     */
    public function test_the_next_waiter_acquires_cleanly_after_the_hand_off(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);

        $state->acquire(9, $channel->park(...));
        $state->markOpen();

        self::assertSame(TxEnd::CLOSE, $state->claimCommit(9));
        $state->handOff($channel->push(...));

        self::assertSame(1, $channel->tokensLeft(), 'the hand-off returned exactly one token');
        $state->acquire(5, $channel->park(...));
        $state->markOpen();
        self::assertTrue($state->holdingTransactionFor(5));
    }

    /**
     * The token and the open flag are distinct: acquiring installs the
     * holder identity while the vendor begin has not answered yet (the
     * landed window between holder-install and transNesting=1). During
     * that window the holder exists but no transaction does, so the
     * query fast-path must stay closed.
     */
    public function test_the_holder_is_installed_before_the_transaction_is_open(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);

        $state->acquire(3, $channel->park(...));

        self::assertSame(3, $state->holder());
        self::assertSame(0, $state->nestingLevel());
        self::assertFalse($state->holdingTransactionFor(3), 'a lease without an open transaction must not bypass the query lock');

        $state->markOpen();

        self::assertSame(1, $state->nestingLevel());
        self::assertTrue($state->holdingTransactionFor(3));
    }

    /**
     * Design §1.5 + the equivalent of the estate's TXMUTEX-8: releasing
     * clears holder/poison FIRST and the push is the LAST write to the
     * shared ledger, because the woken waiter runs synchronously inside
     * push(). If the ledger still named a holder at push time, the woken
     * coroutine would observe a forked mutex.
     */
    public function test_the_hand_off_clears_the_ledger_before_the_push_is_issued(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);

        $state->acquire(4, $channel->park(...));
        $state->markOpen();
        $state->markPoisoned();

        // The landed ceremony for a poisoned holder: refusal first, then
        // the caller's catch-rollback completes the lease - and THAT is
        // the path whose hand-off must be observable clean.
        self::assertSame(TxEnd::POISON_REFUSED, $state->claimCommit(4));
        self::assertSame(TxEnd::CLOSE, $state->claimRollback(4));
        $state->handOff($channel->push(...));

        self::assertSame(1, $channel->pushCount);
        $observed = $channel->pushObservations[0];
        self::assertSame(MysqlTxLockState::FREE_HOLDER, $observed['holder'], 'at push time the ledger must already read free');
        self::assertSame(0, $observed['nesting']);
        self::assertFalse($observed['poisoned']);
        self::assertSame($observed['holder'], $state->holder(), 'nothing may change after the push - it is the last write');
        self::assertSame($observed['nesting'], $state->nestingLevel());
    }

    /**
     * v0.59 bank row 1: with a bound cid, handOff speaks the sibling
     * bind-and-check idiom — a stranger hand-off is FULLY INERT (no
     * ledger write, no token), so a stale finally arm or a
     * reconnect-stranded lease can never clear another lease's hold or
     * push a phantom token. The holder's own bound hand-off then runs in
     * the §1.5 order unchanged.
     */
    public function test_a_bound_hand_off_by_a_stranger_is_fully_inert(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);

        $state->acquire(5, $channel->park(...));
        $state->markOpen();

        self::assertFalse($state->handOff($channel->push(...), 9), 'a stranger hand-off is refused');
        self::assertSame(5, $state->holder(), 'the refusal must not free the holder');
        self::assertSame(1, $state->nestingLevel(), 'the refusal must not floor the flag');
        self::assertSame(0, $channel->pushCount, 'the refusal emits no token');

        $state->markPoisoned();
        self::assertFalse($state->handOff($channel->push(...), 9), 'still refused with the mark set');
        self::assertTrue($state->isPoisoned(), 'the refusal must not consume the holder poison');

        // The holder finishes its own ceremony the landed way: CLOSE
        // floors the flag, then the bound release runs, in §1.5 order.
        self::assertSame(TxEnd::POISON_REFUSED, $state->claimCommit(5));
        self::assertSame(TxEnd::CLOSE, $state->claimRollback(5));
        self::assertTrue($state->handOff($channel->push(...), 5));
        self::assertSame(1, $channel->pushCount);
        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertSame(0, $state->nestingLevel());
        self::assertFalse($state->isPoisoned());
    }

    /**
     * v0.59 bank row 1, legacy arm: with no cid bound, handOff keeps the
     * posture it shipped with — the release runs unconditionally, even
     * while some other coroutine is named holder — the byte-compatible
     * path for landed finally arms until their lanes pass an identity.
     */
    public function test_an_unbound_hand_off_passthrough_keeps_the_legacy_posture(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);

        $state->acquire(5, $channel->park(...));
        $state->markOpen();

        self::assertTrue($state->handOff($channel->push(...)), 'the unbound arm always runs');
        self::assertSame(1, $channel->pushCount);
        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertSame(
            1,
            $state->nestingLevel(),
            'the release law is unchanged: handOff never floors the flag, bind or no bind'
        );
    }

    /**
     * Incident 1.2 shape (hub): commit/rollback by anyone who is not the
     * holder must be inert - and the guard sits ABOVE the poison check,
     * so a stranger cannot even consume the holder's one-shot refusal
     * budget. The holder's own commit still refuses afterwards.
     */
    public function test_a_stranger_can_neither_end_nor_deplete_the_holders_transaction(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);

        $state->acquire(2, $channel->park(...));
        $state->markOpen();
        $state->markPoisoned();

        self::assertSame(TxEnd::INERT, $state->claimCommit(8));
        self::assertTrue($state->isPoisoned(), 'a stranger commit may not consume the holder refusal');
        self::assertSame(2, $state->holder());
        self::assertSame(1, $state->nestingLevel());

        self::assertSame(TxEnd::INERT, $state->claimRollback(8));
        self::assertTrue($state->isPoisoned());
        self::assertSame(2, $state->holder());
        self::assertSame(1, $state->nestingLevel());
        self::assertSame(0, $channel->pushCount, 'the Inert arms touch no token');

        // The holder still owns its transaction and its refusal.
        self::assertSame(TxEnd::POISON_REFUSED, $state->claimCommit(2));
    }

    /**
     * Poison is one-shot: exactly one commit is denied, the ledger keeps
     * the holder and its open transaction intact for the caller's
     * catch-rollback, and the next commit from the same holder proceeds.
     * (Landed order: refusal does NOT release; the caller's rollback
     * path ends the transaction.)
     */
    public function test_the_poisoned_commit_is_refused_exactly_once(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);

        $state->acquire(1, $channel->park(...));
        $state->markOpen();
        $state->markPoisoned();

        self::assertSame(TxEnd::POISON_REFUSED, $state->claimCommit(1));
        self::assertFalse($state->isPoisoned(), 'the refusal consumes the mark - one denial per incident');
        self::assertSame(1, $state->holder(), 'the refusal must not release the lease');
        self::assertSame(1, $state->nestingLevel());
        self::assertSame(0, $channel->pushCount, 'the refusal emits no token - the holder still owes an end');

        // Caller's catch-rollback completes the ceremony.
        self::assertSame(TxEnd::CLOSE, $state->claimRollback(1));
        $state->handOff($channel->push(...));
        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());

        // A fresh transaction is clean: refusal seen, next one proceeds.
        $state->acquire(1, $channel->park(...));
        $state->markOpen();
        self::assertSame(TxEnd::CLOSE, $state->claimCommit(1));
    }

    /**
     * CLI (no coroutine) callers bypass the mutex: the claims delegate to
     * the vendor. But the landed order keeps the poison refusal ABOVE
     * the passthrough arm for commit, while rollback consumes silently
     * (an explicit rollback is "the fresh start" that clears the mark).
     */
    public function test_cli_callers_pass_through_with_the_landed_priority(): void
    {
        $state = new MysqlTxLockState();

        self::assertSame(TxEnd::PASS_THROUGH, $state->claimCommit(-1));
        self::assertSame(TxEnd::PASS_THROUGH, $state->claimRollback(-1));

        $state->markPoisoned();
        self::assertSame(TxEnd::POISON_REFUSED, $state->claimCommit(-1), 'refusal sits above the passthrough arm');
        self::assertFalse($state->isPoisoned());

        $state->markPoisoned();
        self::assertSame(TxEnd::PASS_THROUGH, $state->claimRollback(-1));
        self::assertFalse($state->isPoisoned(), 'a CLI rollback consumes the poison without refusal');
    }

    /**
     * Incident 1.4 shape (server, cure A4): the query fast-path may only
     * short-circuit for the holder OF AN OPEN transaction - nesting and
     * holder identity both required. Without the holder term a parked
     * foreign write lands inside the holder's transaction and is erased
     * when the holder rolls back.
     */
    public function test_the_query_gate_requires_holder_identity_and_an_open_transaction(): void
    {
        // (a) holder with open transaction: gate OPEN only for the holder.
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);
        $state->acquire(6, $channel->park(...));
        $state->markOpen();
        self::assertTrue($state->holdingTransactionFor(6));
        self::assertFalse($state->holdingTransactionFor(7), 'a foreign coroutine must serialize (the pre-A4 shape returned true here)');

        // (b) holder installed, transaction not yet open: still closed.
        $idle = new MysqlTxLockState();
        $idleChannel = $this->fakeChannel($idle);
        $idle->acquire(6, $idleChannel->park(...));
        self::assertFalse($idle->holdingTransactionFor(6), 'the install window must serialize too');

        // (c) nobody holding: closed.
        $free = new MysqlTxLockState();
        self::assertFalse($free->holdingTransactionFor(6));
    }

    /**
     * The vendor-begin failure arms release a lease that was installed
     * but never opened - but ONLY while still holding it (a hand-off in
     * the meantime must not be clobbered by a late error cleanup).
     */
    public function test_abandon_releases_only_while_the_caller_still_holds_the_lease(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state, 0);

        // Landed server catch: holder===cid -> releaseTransLock().
        $state->acquire(2, $channel->parkTrue(...));
        self::assertSame(2, $state->holder());
        self::assertTrue($state->abandonIfHeld(2, $channel->push(...)));
        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertSame(1, $channel->pushCount, 'the abandon path hands the token onward');

        // Late cleanup from a different coroutine: inert.
        $state->acquire(2, $channel->park(...));
        self::assertFalse($state->abandonIfHeld(5, $channel->push(...)));
        self::assertSame(2, $state->holder(), 'a stranger cleanup must not release the holder');

        // Abandon when the ledger is already free: inert, no phantom token.
        $state->abandonIfHeld(2, $channel->push(...));
        $before = $channel->pushCount;
        self::assertFalse($state->abandonIfHeld(2, $channel->push(...)));
        self::assertSame($before, $channel->pushCount, 'no token may be minted for an unheld lease');

        // Abandon from an OPEN lease lowers the nesting flag with the holder
        // (the hub's refusal-path release — landed flag cleared on every end
        // path, F-1). A stale nesting would LogicException the next markOpen.
        $state->acquire(7, $channel->parkTrue(...));
        $state->markOpen();
        self::assertTrue($state->abandonIfHeld(7, $channel->push(...)));
        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertSame(0, $state->nestingLevel(), 'release from an open lease must flatten the flag');
        $state->acquire(8, $channel->parkTrue(...));
        $state->markOpen();
        self::assertSame(1, $state->nestingLevel(), 'the next lease opens cleanly on the flattened ledger');
    }

    /**
     * The begin-threw cleanup arm (catch-Throwable in BOTH landed repos)
     * releases the lease WITHOUT consuming the poison mark: a failed
     * BEGIN must not spend a refusal budget an earlier scope earned.
     * The falsy-parent fold keeps the clearing default (r1 truth: both
     * landed folds clear poison there).
     */
    public function test_abandon_can_release_without_consuming_the_poison_budget(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state, 0);

        // Poison earned by a previous scope, then a fresh lease whose vendor
        // begin throws: the landed release writes nothing to the mark.
        $state->markPoisoned();
        $state->acquire(4, $channel->parkTrue(...));
        self::assertTrue($state->abandonIfHeld(4, $channel->push(...), clearPoison: false));
        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertSame(1, $channel->pushCount, 'the throw arm still hands the token onward');
        self::assertTrue($state->isPoisoned(), 'a failed begin must not spend the refusal budget');

        // The preserved budget is then consumed by exactly one commit refusal.
        self::assertSame(TxEnd::POISON_REFUSED, $state->claimCommit(MysqlTxLockState::FREE_HOLDER));
        self::assertFalse($state->isPoisoned());

        // And the default fold still clears (falsy-parent law unchanged).
        $state->markPoisoned();
        $state->acquire(4, $channel->parkTrue(...));
        self::assertTrue($state->abandonIfHeld(4, $channel->push(...)));
        self::assertFalse($state->isPoisoned(), 'the falsy fold still consumes the stale mark');
    }

    /**
     * Brief artifact "poison-mark + cleared-on-reconnect": a fresh socket
     * cannot carry the previous socket's transaction, so the reconnect
     * path flattens the whole ledger. The machine touches no token here
     * - channel lifecycle is the consumer's domain.
     */
    public function test_clearing_on_reconnect_flattens_the_whole_ledger(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);
        $state->acquire(3, $channel->park(...));
        $state->markOpen();
        $state->markPoisoned();

        $state->clearOnReconnect();

        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertSame(0, $state->nestingLevel());
        self::assertFalse($state->isPoisoned());
        self::assertSame(0, $channel->pushCount, 'the reconnect path emits no token - channel lifecycle is the consumers');
    }

    /**
     * Marking is idempotent (the landed execute arm sets the flag after
     * the rollback unconditionally); the ONE-SHOT property lives in the
     * refusal consumption, not the mark.
     */
    public function test_the_poison_mark_is_idempotent_and_clearing_is_available(): void
    {
        $state = new MysqlTxLockState();

        $state->markPoisoned();
        $state->markPoisoned();
        $state->markPoisoned();
        self::assertTrue($state->isPoisoned());

        $state->clearPoison();
        self::assertFalse($state->isPoisoned());
    }

    /**
     * markOpen() is exactly-once per lease: a second open under the same
     * holder is the nested-begin shape the ledger refuses at acquire(),
     * so reaching markOpen twice is caller misuse - fail loud.
     */
    public function test_mark_open_twice_is_a_loud_misuse(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);
        $state->acquire(7, $channel->park(...));
        $state->markOpen();

        $this->expectException(\LogicException::class);
        $state->markOpen();
    }

    /**
     * Design §1.5 equivalence T5: a holder that ALREADY has an open
     * transaction may not begin again - the refusal wording is the
     * vendor message, byte-for-byte, because callers throughout the
     * estate catch it by text.
     *
     * T5 venue-noise posture, stated per the design mandate so it never
     * silently becomes error-surface (v0.59 bank row 4): the machine's
     * nesting refusal fires BEFORE any vendor call, so the
     * null-errorInfo warnings that ride a real nested start
     * (vendor Connection.php:2000 under workerman) are unreachable from
     * this substrate - every run asserting this pin is zero-warning by
     * construction: the chosen ASSERT-NOISE posture, held by the
     * refusal-before-vendor ordering itself. Consumers whose real-DB
     * nesting tests reach the vendor carry their own posture rows;
     * that noise there is disclosed venue, never a law of this class.
     */
    public function test_a_nested_begin_refuses_with_the_vendor_message(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);
        $state->acquire(7, $channel->park(...));
        $state->markOpen();

        try {
            $state->acquire(7, $channel->parkTrue(...));
            self::fail('a nested begin by the holder must refuse');
        } catch (PDOException $e) {
            self::assertSame(
                MysqlTxLockState::NESTED_BEGIN_MESSAGE,
                $e->getMessage(),
                'the refusal is the vendor text itself'
            );
            self::assertSame('There is already an active transaction', $e->getMessage());
        }

        self::assertSame(1, $state->nestingLevel(), 'the refusal leaves the open transaction alone');
        self::assertSame(0, $channel->pushCount, 'the refusal emits no token');
    }

    /**
     * The nesting guard keeps the flag in {0,1} for acquire/refusal, but
     * the landed code also carries the unreachable-looking >1 decrement
     * arms (depth-check law). Both are pinned here the way they are
     * reachable in the machine: as claim results that tell the caller to
     * run the vendor end and decrement on success.
     */
    public function test_the_nested_arms_decrement_only_from_above_the_flag_line(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);

        $state->acquire(4, $channel->park(...));
        $state->markOpen();

        // Landed nesting>1 branches: at nesting 1 the claim is CLOSE, not NESTED.
        self::assertSame(TxEnd::CLOSE, $state->claimCommit(4));
        self::assertSame(0, $state->nestingLevel(), 'Close was claimed with the flag already moved to 0');

        // decrement() is the Nested-arm bookkeeping; below the line it is a no-op.
        $state->decrement();
        self::assertSame(0, $state->nestingLevel(), 'decrement never drives the flag negative');
    }

    /**
     * Sequence walk (design §5 T7 spirit): many full ceremonies with two
     * interleaved coroutine ids never multiply tokens - capacity stays
     * 1, the ledger ends free, every acquire consumed exactly the token
     * its matching hand-off returned.
     */
    public function test_repeated_ceremonies_never_multiply_the_token(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state, 1);

        for ($round = 0; $round < 25; $round++) {
            $cid = $round % 2 === 0 ? 10 : 20;
            $state->acquire($cid, $channel->park(...));
            self::assertSame(0, $channel->tokensLeft(), 'the acquire alone holds the one token');
            $state->markOpen();
            self::assertSame(TxEnd::CLOSE, $state->claimCommit($cid));
            $state->handOff($channel->push(...));
            self::assertSame(1, $channel->tokensLeft(), 'the hand-off returned exactly one token - capacity never grows');
        }

        self::assertSame(25, $channel->pushCount);
        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertSame(0, $state->nestingLevel());
        self::assertFalse($state->isPoisoned());
    }

    /**
     * Poison survives its own marking sequence: the landed execute arm
     * rolls back FIRST and marks AFTER (the rollback runs the machine's
     * own consumption paths - marking before would be eaten by them).
     * Pinned as the ceremony callers must follow.
     */
    public function test_the_mark_survives_a_cli_rollback_that_runs_first(): void
    {
        $state = new MysqlTxLockState();

        // Landed gone-in-tx order for a CLI-style caller: rollback then mark.
        self::assertSame(TxEnd::PASS_THROUGH, $state->claimRollback(-1));
        $state->markPoisoned();
        self::assertTrue($state->isPoisoned(), 'a mark applied after the rollback must stand');
        self::assertSame(TxEnd::POISON_REFUSED, $state->claimCommit(-1));
    }

    /**
     * Acquire is a coroutine-path API: a negative id bypasses the mutex
     * entirely on the consumer side, so handing it to acquire() is
     * caller misuse and must fail loud rather than install a phantom
     * holder equal to the free sentinel.
     */
    public function test_acquiring_with_a_non_coroutine_id_is_a_loud_misuse(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->fakeChannel($state);

        $this->expectException(\InvalidArgumentException::class);
        $state->acquire(-1, $channel->parkTrue(...));
    }
}

/**
 * In-file double (tests ruleset allows multiple classes per file):
 * a Swoole Channel stand-in that records the machine's observable state
 * at the instant push() runs, so ordering invariants can be asserted
 * from the woken waiter's point of view.
 */
final class FakeMutexChannel
{
    public int $pushCount = 0;

    /** @var list<array{holder: int, nesting: int, poisoned: bool}> */
    public array $pushObservations = [];

    public function __construct(private readonly MysqlTxLockState $state, private int $tokens = 1)
    {
    }

    public function park(): bool
    {
        return $this->tokens-- > 0;
    }

    public function parkTrue(): bool
    {
        return true;
    }

    public function push(): void
    {
        $this->pushCount++;
        $this->tokens++;
        $this->pushObservations[] = [
            'holder' => $this->state->holder(),
            'nesting' => $this->state->nestingLevel(),
            'poisoned' => $this->state->isPoisoned(),
        ];
    }

    public function tokensLeft(): int
    {
        return $this->tokens;
    }
}
