<?php

declare(strict_types=1);

namespace Phlix\Shared\Support;

/**
 * Reconnect Backoff Math - Stepped and Jittered Curves
 *
 * The arithmetic of "wait longer after each failure", shared; the SCHEDULES
 * stay per-role (DESIGN M-15, `C-08`): the server's relay tunnel jitters a
 * 5 -> 300 s exponential, the hub's federation ladder steps a deterministic
 * 5 -> 60 s doubling and parks at the cap across a disabled window - the
 * timer arm/cancel glue is Workerman-side and stays in the repos
 * (`F-10`). What must not drift is the MATH: two hand-copied formulas that
 * disagree are how "thundering herd without jitter can't be reasoned
 * cross-side" stays unfixed, and every new worker now has one law to copy.
 *
 * Both curves are pure arithmetic over ints/floats with an injectable
 * `rng` seam on the jittered arm, which makes the spread bounds testable
 * without a clock or a random draw.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 * @package   Phlix\Shared\Support
 * @since     0.56.0
 */
final class Backoff
{
    /**
     * The deterministic doubling curve: `min(base * factor^attempt, cap)`.
     *
     * This is the hub federation ladder's math (base 5, factor 2, cap 60;
     * `reconnectDelaySeconds = min($delay * 2, CAP)` expressed per-attempt),
     * kept free of the park-at-cap STATE, which belongs to the caller's
     * gate logic.
     *
     * A negative attempt reads as 0 (the first retry); the cap always
     * wins over a base that already exceeds it.
     */
    public static function stepped(int $attempt, int $base, int $cap, int $factor = 2): int
    {
        if ($attempt < 0) {
            $attempt = 0;
        }

        $growth = $attempt > 62 ? $cap : $base * ($factor ** $attempt);

        return (int) min($growth, $cap);
    }

    /**
     * The server relay-consumer curve: the stepped exponential (factor 2)
     * scaled by a `1 +/- jitterRatio` multiplier - the exact live math of
     * `RelayConsumer::scheduleReconnect()`
     * (`$exponential * (1 + $jitter * ((mt_rand() / mt_getrandmax()) * 2 - 1))`),
     * lifted with the random draw behind an injectable seam.
     *
     * @param callable():float|null $rng Returns a unit reading in [0, 1];
     *                                   null uses `mt_rand() / mt_getrandmax()`.
     *                                   0 pins the low edge of the spread,
     *                                   1 the high edge, 0.5 the un-jittered
     *                                   center.
     */
    public static function jittered(
        int $attempt,
        int $base,
        int $cap,
        float $jitterRatio = 0.3,
        ?callable $rng = null,
    ): int {
        $unit = $rng === null ? mt_rand() / mt_getrandmax() : $rng();
        $exponential = self::stepped($attempt, $base, $cap);
        $multiplier = 1 + $jitterRatio * ((2 * $unit) - 1);

        return (int) round($exponential * $multiplier);
    }

    /**
     * The delay-from-state sibling of {@see self::stepped()}: the next
     * step of a backoff whose state is the CURRENT DELAY rather than an
     * attempt index — `min($current * $factor, $cap)`, parking at the
     * cap. Minted for the federation reconnect lane the hub holds as
     * hub-local state (`FederationPeerManager`: `min($delay * 2, CAP)`
     * over the byte-pinned park-at-cap ladder [5, 10, 20, 40, 60, 60]),
     * so that shape finally has a kernel home; the attempt-indexed
     * forms stay untouched.
     *
     * Degenerate state reads as no-wait, mirroring `stepped()`'s
     * `attempt < 0 → 0` convention: a non-positive current delay, a
     * non-positive factor, or a non-positive cap all yield 0 — the
     * curve never manufactures a wait from a state that carries none.
     * Overflow saturates to the cap by comparison (the widened float
     * product simply exceeds it), never to a negative.
     *
     * @param int $current the delay the state machine currently holds, in seconds
     * @param int $factor the step multiplier (the hub ladder uses 2)
     * @param int $cap the park ceiling, in seconds
     */
    public static function nextStepped(int $current, int $factor, int $cap): int
    {
        if ($current <= 0 || $factor <= 0 || $cap <= 0) {
            return 0;
        }

        return (int) min($current * $factor, $cap);
    }

    private function __construct()
    {
    }
}
