<?php

/**
 * Tx Lease Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Database;

use InvalidArgumentException;
use PDOException;
use Phlix\Shared\Database\MysqlTxLockState;
use Phlix\Shared\Database\TxEnd;
use Phlix\Shared\Database\TxLease;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Characterization suite for the per-ceremony lease binding.
 *
 * The machine's own laws (cancel-checked acquire, stranger-inert ends,
 * one-shot poison, §1.5 hand-off ordering, A-1/A-2 abandon floors) are
 * pinned by {@see MysqlTxLockStateTest} and are NOT re-proved here for
 * their own sake. What this suite pins is that the lease (a) delegates
 * every bound verb with the bound identity — never the wrong cid, never
 * a lost seam — and (b) runs the ONE unified ceremony both consumers
 * landed (TXMUTEX-B retrofit: acquire / catch-release-keeping-budget /
 * fold-abandon / open-mark / budget-reset) with its arms observable
 * exactly as the server's and the hub's beginTrans did in the 32+21
 * scenario differentials.
 *
 * @see TxLease
 */
#[CoversClass(TxLease::class)]
final class TxLeaseTest extends TestCase
{
    private function lease(
        MysqlTxLockState $state,
        int $cid,
        LeaseChannel $channel,
    ): TxLease {
        return new TxLease($state, $cid, $channel->park(...), $channel->push(...));
    }

    /**
     * A ledger opened by hand (holder installed, transaction flag up),
     * as any ceremony's mid-state looks.
     */
    private function openLedger(MysqlTxLockState $state, int $cid): LeaseChannel
    {
        $channel = new LeaseChannel($state);
        $state->acquire($cid, $channel->park(...));
        $state->markOpen();

        return $channel;
    }

    /**
     * The bind-time refusal of a non-coroutine id is what keeps the
     * lease's delegate ladder honest: the machine answers cid<0 with
     * PASS_THROUGH arms (CLI delegation), so a lease bound to -1 would
     * silently mis-map every identity check. Fail loud at the seam
     * instead, exactly where the landed consumers branch to the vendor
     * BEFORE ever touching the machine.
     */
    public function test_binding_a_non_coroutine_id_is_a_loud_misuse(): void
    {
        $state = new MysqlTxLockState();
        $channel = new LeaseChannel($state);

        try {
            new TxLease($state, -1, $channel->park(...), $channel->push(...));
            self::fail('a lease bound to the CLI sentinel must refuse at construction');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString(
                'coroutine id',
                $e->getMessage(),
                'the refusal names the misuse it refuses'
            );
        }

        $lease = $this->lease($state, 7, $channel);
        self::assertSame(7, $lease->coroutineId(), 'the bound id is readable for caller-side mapping');
    }

    public function test_bound_reads_mirror_the_ledger(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->openLedger($state, 5);
        $lease = $this->lease($state, 5, $channel);

        self::assertSame(5, $lease->holder());
        self::assertSame(1, $lease->nestingLevel());
        self::assertFalse($lease->isPoisoned());

        $state->markPoisoned();
        self::assertTrue($lease->isPoisoned(), 'reads are live, not snapshots taken at bind time');
    }

    /**
     * holds() is the A4 both-terms predicate bound to the ceremony's id:
     * holder identity alone is NOT holding (the install window), and an
     * open transaction owned by someone else is NOT holding either.
     */
    public function test_holds_requires_bound_identity_and_an_open_transaction(): void
    {
        $state = new MysqlTxLockState();
        $channel = new LeaseChannel($state);

        // Holder, never opened (install window): identity yes, holding no.
        $state->acquire(5, $channel->park(...));
        self::assertFalse($this->lease($state, 5, $channel)->holds());

        // Holder, opened: both terms true.
        $state->markOpen();
        self::assertTrue($this->lease($state, 5, $channel)->holds());

        // Stranger looking at someone else's open transaction: false.
        self::assertFalse($this->lease($state, 8, $channel)->holds());
    }

    public function test_is_holder_answers_for_the_bound_coroutine_only(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->openLedger($state, 5);

        self::assertTrue($this->lease($state, 5, $channel)->isHolder());
        self::assertFalse($this->lease($state, 6, $channel)->isHolder());

        $this->lease($state, 5, $channel)->handOff();
        self::assertFalse($this->lease($state, 5, $channel)->isHolder());
    }

    public function test_the_bound_acquire_installs_the_holder_on_a_token(): void
    {
        $state = new MysqlTxLockState();
        $channel = new LeaseChannel($state);
        $lease = $this->lease($state, 9, $channel);

        $lease->acquire();

        self::assertSame(9, $lease->holder());
        self::assertSame(1, $channel->parkCount, 'the bound park seam is what answered');
        self::assertSame(0, $channel->pushCount, 'acquire never emits a token');
    }

    /**
     * The machine's incident-1.3 law must survive the binding: a
     * cancelled park refuses BEFORE any bookkeeping, so the lease's
     * world is untouched and no phantom token was minted.
     */
    public function test_a_cancelled_park_refuses_through_the_lease_untouched(): void
    {
        $state = new MysqlTxLockState();
        $ownerChannel = $this->openLedger($state, 9);
        $starved = new LeaseChannel($state, 0);

        try {
            $this->lease($state, 5, $starved)->acquire();
            self::fail('a cancelled park must refuse the bound acquire');
        } catch (PDOException $e) {
            self::assertSame(MysqlTxLockState::CANCELLED_ACQUIRE_MESSAGE, $e->getMessage());
        }

        self::assertSame(9, $state->holder(), 'the cancelled waiter did not install itself');
        self::assertSame(1, $state->nestingLevel(), 'the holder transaction is untouched');
        self::assertSame(0, $starved->pushCount, 'a cancelled acquire never emits a token');
        self::assertSame(0, $ownerChannel->pushCount);
    }

    /**
     * Unguarded reentry is caller misuse with a defined answer: the
     * machine's vendor-worded refusal, and the vendor begin is NOT run.
     * (Landed consumers gate on isHolder() first — the D-2 posture —
     * and this pin says the lease does not hide the refusal path.)
     */
    public function test_acquiring_an_open_lease_refuses_with_the_machine_wording(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->openLedger($state, 5);
        $lease = $this->lease($state, 5, $channel);

        $startRan = 0;

        try {
            $lease->begin(static function () use (&$startRan): bool {
                $startRan++;

                return true;
            });
            self::fail('a nested begin on an open lease must refuse');
        } catch (PDOException $e) {
            self::assertSame(MysqlTxLockState::NESTED_BEGIN_MESSAGE, $e->getMessage());
        }

        self::assertSame(0, $startRan, 'the vendor begin must never be reached');
        self::assertSame(1, $lease->nestingLevel(), 'the refusal changes nothing');
        self::assertSame(0, $channel->pushCount);
    }

    /**
     * The install window (holder identity, transaction flag down) is the
     * one state where a bound acquire proceeds WITHOUT parking — the
     * lease must inherit that silence, not double-count a token.
     */
    public function test_the_install_window_proceeds_without_parking(): void
    {
        $state = new MysqlTxLockState();
        $channel = new LeaseChannel($state);
        $state->acquire(5, $channel->park(...));
        $parkAfter = $channel->parkCount;

        $lease = $this->lease($state, 5, $channel);
        self::assertTrue($lease->begin(static fn(): bool => true));

        self::assertSame($parkAfter, $channel->parkCount, 'the silent proceed never parked again');
        self::assertSame(1, $lease->nestingLevel());
        self::assertSame(0, $channel->pushCount, 'an open begin emits no token');
    }

    public function test_a_successful_begin_opens_the_lease_without_pushing(): void
    {
        $state = new MysqlTxLockState();
        $channel = new LeaseChannel($state);

        self::assertTrue($this->lease($state, 5, $channel)->begin(static fn(): bool => true));

        self::assertSame(5, $state->holder());
        self::assertSame(1, $state->nestingLevel());
        self::assertFalse($state->isPoisoned());
        self::assertSame(0, $channel->pushCount);
    }

    /**
     * Reentering through the install window with a stale mark on the
     * books: markOpen's budget reset must leave begin's post-condition
     * ("a fresh transaction starts with a clean refusal budget") true,
     * and the ceremony's unconditional budget-reset tail keeps that
     * law stated at the ceremony level exactly as both landed
     * consumers' beginTrans tails do.
     */
    public function test_a_successful_begin_resets_a_stale_poison_budget(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->openLedger($state, 7);
        // A claimed-rollback left the holder in place with the flag down.
        self::assertSame(TxEnd::CLOSE, $state->claimRollback(7));
        $state->markPoisoned();
        self::assertSame(7, $state->holder());
        self::assertSame(0, $state->nestingLevel());

        self::assertTrue($this->lease($state, 7, $channel)->begin(static fn(): bool => true));

        self::assertFalse($state->isPoisoned(), 'begin must end with a clean refusal budget');
        self::assertSame(1, $state->nestingLevel());
        self::assertSame(0, $channel->pushCount);
    }

    /**
     * The falsy fold (vendor said no): the A-1/A-2 floors run through
     * the bound seams — holder free, flag flat, one token back, budget
     * cleared by the clearing default.
     */
    public function test_a_falsy_begin_folds_the_lease_back_clean(): void
    {
        $state = new MysqlTxLockState();
        $channel = new LeaseChannel($state);

        self::assertFalse($this->lease($state, 5, $channel)->begin(static fn(): bool => false));

        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertSame(0, $state->nestingLevel());
        self::assertFalse($state->isPoisoned());
        self::assertSame(1, $channel->pushCount, 'the fold returns the token exactly once');
    }

    public function test_the_falsy_fold_clears_a_stale_poison_budget(): void
    {
        $state = new MysqlTxLockState();
        $channel = new LeaseChannel($state);
        $state->acquire(7, $channel->park(...));
        $state->markPoisoned();

        self::assertFalse($this->lease($state, 7, $channel)->begin(static fn(): bool => false));

        self::assertFalse($state->isPoisoned(), 'the clearing default is the fold arm (A-1)');
        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertSame(1, $channel->pushCount);
    }

    /**
     * The F-3 arm — the ceremony's sharpest edge, the exact miswire
     * class (a flipped clearPoison flag) the retrofit's M2/HM2 mutation
     * teeth convict consumer-side. A BEGIN that THREW releases the
     * lease without spending the one-shot refusal budget an earlier
     * scope earned, and the original Throwable keeps its identity on
     * the way out.
     */
    public function test_a_throwable_begin_releases_without_spending_the_poison_budget(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->openLedger($state, 7);
        self::assertSame(TxEnd::CLOSE, $state->claimRollback(7));
        $state->markPoisoned();
        self::assertSame(7, $state->holder());

        $boom = new RuntimeException('vendor begin died');

        try {
            $this->lease($state, 7, $channel)->begin(
                static function () use ($boom): bool {
                    throw $boom;
                }
            );
            self::fail('the original Throwable must be rethrown');
        } catch (RuntimeException $thrown) {
            self::assertSame($boom, $thrown, 'the rethrow keeps the exact original identity');
        }

        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder(), 'the throw path releases');
        self::assertSame(0, $state->nestingLevel());
        self::assertTrue($state->isPoisoned(), 'a failed BEGIN must not consume the refusal budget');
        self::assertSame(1, $channel->pushCount);
    }

    public function test_a_throwable_begin_on_a_clean_ledger_releases_quietly(): void
    {
        $state = new MysqlTxLockState();
        $channel = new LeaseChannel($state);

        try {
            $this->lease($state, 5, $channel)->begin(
                static fn(): bool => throw new RuntimeException('vendor begin died')
            );
            self::fail('the original Throwable must be rethrown');
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertFalse($state->isPoisoned(), 'a clean ledger stays clean — nothing was spent');
        self::assertSame(1, $channel->pushCount);
    }

    public function test_a_cancelled_park_never_reaches_the_vendor_start(): void
    {
        $state = new MysqlTxLockState();
        $this->openLedger($state, 9);
        $starved = new LeaseChannel($state, 0);
        $startRan = 0;

        try {
            $this->lease($state, 5, $starved)->begin(
                static function () use (&$startRan): bool {
                    $startRan++;

                    return true;
                }
            );
            self::fail('a cancelled park must refuse begin()');
        } catch (PDOException $e) {
            self::assertSame(MysqlTxLockState::CANCELLED_ACQUIRE_MESSAGE, $e->getMessage());
        }

        self::assertSame(0, $startRan, 'the acquire law runs BEFORE any start');
        self::assertSame(0, $starved->pushCount);
    }

    /**
     * The end-side granular verbs bind the same identity the ceremony
     * used to open: CLOSE here, and the bound push seam is what the
     * hand-off actually fires — the lease never reaches the ledger with
     * a borrowed seam.
     */
    public function test_end_side_claims_and_hand_off_run_through_the_binding(): void
    {
        $state = new MysqlTxLockState();
        $channel = new LeaseChannel($state);
        $lease = $this->lease($state, 3, $channel);

        $lease->begin(static fn(): bool => true);
        self::assertSame(TxEnd::CLOSE, $lease->claimCommit());
        self::assertSame(0, $state->nestingLevel(), 'the CLOSE arm floored the flag through the binding');

        $lease->handOff();

        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertSame(1, $channel->pushCount);
        self::assertFalse($lease->isPoisoned());
    }

    /**
     * A stranger lease must not be able to deplete or disturb the real
     * holder's ceremony through the binding either: inert claims, inert
     * abandon, no token emitted.
     */
    public function test_a_stranger_lease_stays_inert_over_the_holders_ceremony(): void
    {
        $state = new MysqlTxLockState();
        $holderChannel = $this->openLedger($state, 3);
        $holder = $this->lease($state, 3, $holderChannel);
        $state->markPoisoned();

        $strangerChannel = new LeaseChannel($state);
        $stranger = $this->lease($state, 8, $strangerChannel);

        self::assertSame(TxEnd::INERT, $stranger->claimCommit(), 'stranger sits ABOVE the poison check');
        self::assertTrue($holder->isPoisoned(), 'the holder budget survived the stranger claim');
        self::assertSame(TxEnd::INERT, $stranger->claimRollback());
        self::assertFalse($stranger->abandon(), 'a non-holder abandon is inert...');
        self::assertFalse($stranger->abandon(false), '...on both floors');
        self::assertSame(0, $strangerChannel->pushCount, 'and never emits a token');
        self::assertTrue($holder->holds(), 'the holder transaction stands untouched');
    }

    /**
     * v0.59 bank row 1 through the lease: the bound hand-off refuses a
     * stranger fully inertly — the holder's ceremony stands and no token
     * leaves the stranger's channel — while the holder's own bound
     * hand-off runs and reports truth.
     */
    public function test_a_stranger_lease_hand_off_is_refused_by_the_binding(): void
    {
        $state = new MysqlTxLockState();
        $holderChannel = $this->openLedger($state, 3);
        $holder = $this->lease($state, 3, $holderChannel);

        $strangerChannel = new LeaseChannel($state);
        $stranger = $this->lease($state, 8, $strangerChannel);

        self::assertFalse($stranger->handOff(), 'a non-holder hand-off is inert...');
        self::assertSame(3, $state->holder(), '...the holder stands...');
        self::assertSame(1, $state->nestingLevel(), '...and the flag is untouched');
        self::assertSame(0, $strangerChannel->pushCount, '...and no phantom token is pushed');

        self::assertTrue($holder->handOff(), 'the holder own release runs through the same binding');
        self::assertSame(1, $holderChannel->pushCount);
        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
    }

    /**
     * The landed server ceremony through the lease, end to end: the
     * poison refusal keeps the lease (holder + flag stand, the caller's
     * rollback finishes the job), and the rollback claim CLOSEs so the
     * hand-off can run. The hub's divergent release-on-refusal posture
     * is consumer-side by design — the lease exposes the verdict, it
     * does not pick a repo's end sequence.
     */
    public function test_the_poison_refusal_finishes_through_the_rollback_arm(): void
    {
        $state = new MysqlTxLockState();
        $channel = new LeaseChannel($state);
        $lease = $this->lease($state, 3, $channel);

        $lease->begin(static fn(): bool => true);
        $lease->markPoisoned();

        self::assertSame(TxEnd::POISON_REFUSED, $lease->claimCommit());
        self::assertTrue($lease->isHolder(), 'the refusal leaves the lease held...');
        self::assertTrue($lease->holds(), '...and open: the caller must finish via rollback');
        self::assertSame(0, $channel->pushCount, 'the refusal consumed the mark, not the token');

        self::assertSame(TxEnd::CLOSE, $lease->claimRollback());
        $lease->handOff();

        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertSame(1, $channel->pushCount);
        self::assertFalse($lease->isPoisoned(), 'the refusal spent exactly its one denial');
    }

    /**
     * decrement() binds the machine's above-the-flag-line law unchanged:
     * reachable state keeps the flag in {0,1}, so the bound verb is the
     * NESTED-arm bookkeeping pin only — a no-op below the line, never
     * driving the flag negative.
     */
    public function test_bound_decrement_respects_the_flag_line(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->openLedger($state, 4);
        $lease = $this->lease($state, 4, $channel);

        self::assertSame(TxEnd::CLOSE, $lease->claimCommit());
        $lease->decrement();
        self::assertSame(0, $lease->nestingLevel(), 'decrement never drives the flag negative');
    }

    /**
     * The reconnect flatten binds the whole-machine law unchanged: every
     * ledger term flat, no token pushed — the socket that carried the
     * waiters is gone.
     */
    public function test_clear_on_reconnect_flattens_through_the_binding(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->openLedger($state, 5);
        $lease = $this->lease($state, 5, $channel);
        $lease->markPoisoned();

        $lease->clearOnReconnect();

        self::assertSame(MysqlTxLockState::FREE_HOLDER, $state->holder());
        self::assertSame(0, $state->nestingLevel());
        self::assertFalse($state->isPoisoned());
        self::assertSame(0, $channel->pushCount, 'reconnect flattening never emits tokens');
    }

    /**
     * Poison marking and clearing are idempotent through the binding
     * (the machine's law), and the two one-word budget verbs answer.
     */
    public function test_poison_verbs_delegate_exactly(): void
    {
        $state = new MysqlTxLockState();
        $channel = $this->openLedger($state, 5);
        $lease = $this->lease($state, 5, $channel);

        $lease->markPoisoned();
        $lease->markPoisoned();
        self::assertTrue($lease->isPoisoned());

        $lease->clearPoison();
        self::assertFalse($lease->isPoisoned());
        $lease->clearPoison();
        self::assertFalse($lease->isPoisoned());
    }
}

/**
 * In-file double (tests ruleset allows multiple classes per file): the
 * consumer-side Channel stand-in — park() answers token availability and
 * push() records the ledger as the waking waiter would see it, so the
 * §1.5 order law remains observable through the lease's bound seams.
 */
final class LeaseChannel
{
    public int $parkCount = 0;

    public int $pushCount = 0;

    /** @var list<array{holder: int, nesting: int, poisoned: bool}> */
    public array $pushObservations = [];

    public function __construct(
        private readonly MysqlTxLockState $state,
        private int $tokens = 1,
    ) {
    }

    public function park(): bool
    {
        $this->parkCount++;

        return $this->tokens-- > 0;
    }

    public function push(): void
    {
        $this->pushCount++;
        $this->pushObservations[] = [
            'holder' => $this->state->holder(),
            'nesting' => $this->state->nestingLevel(),
            'poisoned' => $this->state->isPoisoned(),
        ];
    }
}
