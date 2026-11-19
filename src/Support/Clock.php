<?php

declare(strict_types=1);

namespace Phlix\Shared\Support;

/**
 * Millisecond Clock - the Round Law for unixMs()
 *
 * One stamp law for the real-time estate (DESIGN M-14, `C-05`). The two
 * hosts currently disagree at the root of every ms-arithmetic:
 * - the server truncates - `Messages::nowMs()` delegates to a
 *   `(int) (microtime(true) * 1000)` floor-toward-zero read;
 * - the hub rounds - `SyncPlayRelayWorker::nowMs()` is
 *   `(int) round(microtime(true) * 1000)`.
 *
 * Shared ships ROUND. Rationale, on the record: the clients inject
 * `Date.now()`, which is a rounded integer too, so hub + TypeScript + this
 * class agree on the same conversion and the server's truncation is the
 * systematic -1 ms side of the C-03/C-04 anchor arithmetic skew.
 *
 * Owner row O-9 is the OPEN gate on the server adoption leg: flipping
 * `Messages::nowMs()` onto this law moves ms-arithmetic golden pins, and the
 * S1 adoption commit must carry that as a DISCLOSED golden move. The
 * component ships with the round default regardless; nothing in the two
 * repos has switched yet, so no behavior changes before that leg.
 *
 * This class is a static seam, not a DI clock; it is deliberately shaped so
 * a caller-facing `Psr\Clock\ClockInterface` adapter is one-line friendly
 * (psr/clock is already a dependency of this package).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 * @package   Phlix\Shared\Support
 * @since     0.56.0
 */
final class Clock
{
    /**
     * Current Unix time in whole milliseconds, ROUND law.
     */
    public static function unixMs(): int
    {
        return self::msFromUnixSeconds(microtime(true));
    }

    /**
     * Current Unix time in whole seconds.
     */
    public static function unixSec(): int
    {
        return time();
    }

    /**
     * The conversion {@see unixMs()} applies, exposed as a pure seam so the
     * .499/.500 boundary fixtures can pin the ROUND law without racing the
     * system clock.
     *
     * @param float $unixSeconds A `microtime(true)`-shaped reading.
     */
    public static function msFromUnixSeconds(float $unixSeconds): int
    {
        return (int) round($unixSeconds * 1000);
    }

    private function __construct()
    {
    }
}
