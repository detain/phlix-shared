<?php

/**
 * Grant Consume.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Auth;

use Phlix\Shared\Database\WriteResult;

/**
 * Verdict vocabulary for the atomic refresh-grant consume (M-05).
 *
 * The consume is the arbiter of a refresh race: `UPDATE … AND consumed_at IS
 * NULL` on the grant row, first writer wins. The server's `markGrantConsumed`
 * already returns that verdict as a bool; this enum names the three states a
 * caller must distinguish — the hub parity fix (S2) returns it from a
 * previously fire-and-forget `: void` seam, which let two concurrent
 * refreshes of the same grant both proceed to mint.
 *
 * `Indeterminate` is for stores whose verdict cannot be observed from the
 * write (e.g. a driver error after the statement began): callers treat it as
 * NOT consumed — fail closed, refuse the mint, same as a lost race.
 *
 * @package Phlix\Shared\Auth
 * @since 0.56.0
 */
enum GrantConsume: string
{
    /** This caller's write flipped the grant from unconsumed to consumed. */
    case Consumed = 'consumed';

    /** The grant was already consumed (or is gone): the race is lost. */
    case NotConsumed = 'not_consumed';

    /** The write's verdict could not be observed — treat as lost, refuse. */
    case Indeterminate = 'indeterminate';

    /**
     * The consumer-facing reading: did THIS caller's write win the race?
     *
     * `Consumed` alone is truth. A lost race and an unobservable verdict
     * share the fail-closed answer — refuse the mint — which is the law
     * the header already states for `Indeterminate`, extended to every
     * non-won case: a shim can ask the one question without enumerating
     * cases, so `markGrantConsumed(): bool` consumers widen onto this
     * vocabulary byte-compatibly (v0.59 bank row 5; the S2-ADOPT 2.4
     * charter — shared now carries both the verdict and its bool reading).
     */
    public function won(): bool
    {
        return $this === self::Consumed;
    }

    /**
     * Translate a write verdict from the driver into the vocabulary.
     *
     * The consume rides `Database\WriteResult`, the estate's measured
     * contract for what a write statement answers:
     *  - a verdict that cannot be observed (`false`/`null`) is
     *    INDETERMINATE — callers fail closed, same refusal as a lost race;
     *  - one-or-more affected rows is the WON consume (first writer);
     *  - zero affected rows means the guard matched nothing — the grant
     *    was already consumed or is gone: the race is LOST.
     *
     * Consumer arbiter sites (server `markGrantConsumed`, the hub seam
     * kept bool by S2-ADOPT 2.4) replace their local math with this call
     * and hand existing callers `->won()` — verdict for verdict the same
     * answer, with the third state now nameable at the seams that want it.
     *
     * @param mixed $result the driver's answer to the consume statement
     */
    public static function fromWriteVerdict(mixed $result): self
    {
        if (WriteResult::wroteNothing($result)) {
            return self::Indeterminate;
        }

        return WriteResult::affectedRows($result) >= 1 ? self::Consumed : self::NotConsumed;
    }
}
