<?php

/**
 * Grant Consume.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Auth;

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
}
