<?php

/**
 * LivenessWindows.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Relay;

use InvalidArgumentException;

/**
 * The ping-cadence / reap-window pair of a keepalive surface, with the
 * estate's 3x liveness-margin law made executable (v0.57 leg 0.11,
 * M-22 / C-09).
 *
 * Provenance — the law existed only as prose on three surfaces:
 *  - hub `src/SyncPlay/SyncPlayRelayWorker.php` LIVENESS_PING_INTERVAL_SECONDS
 *    = 30 / LIVENESS_REAP_AFTER_SILENCE_SECONDS = 90: "90s against the 30s
 *    cadence is the same 3x margin (two full missed-ping cycles of grace for
 *    a slow reconnect-free network)";
 *  - hub `src/Relay/IdleReaper.php` DEFAULT_STALE_THRESHOLD_SECONDS = 90,
 *    HB-0.1 COUPLING: the window "MUST stay strictly greater than the
 *    server's relay ping interval" and "The default 90s >> 30s gives ~3
 *    heartbeats of headroom; keep that margin if either value is tuned";
 *  - server `src/Hub/RelayConfig.php` ping cadence 30
 *    (PHLIX_RELAY_PING_INTERVAL).
 *
 * Divergence disposition (charter-sanctioned): the VO asserts the STRICTER
 * of the two documented laws — `reap >= 3 x ping` subsumes the
 * strictly-greater MUST, and the exact-3x shipped pairs pass on the
 * inclusive boundary. The executable law is for TRUE ping/reap axes; per
 * the charter API it does NOT model the server watchdog's additive grace
 * shape (`pingInterval + pingTimeout`, default 30+10=40s) — that pair sits
 * below 3x ping by design of its own docblock (one heartbeat cycle +
 * grace), so the server adoption leg (design 1.7, PUSH-GATED) validates the
 * VO against the cadence pair it governs, not the raw timeout. IdleReaper's
 * 60s value is its scan frequency, not a ping — its VO axis is (server ping
 * 30, window 90), per its own coupling docblock.
 *
 * The ping frame byte itself is codec territory (FrameCodec) and
 * deliberately absent here; the shared test pins that negative law.
 * Values < 1 second are refused fail-fast at construction.
 *
 * @since 0.57.0
 */
final class LivenessWindows
{
    /**
     * The minimum headroom the reap window must give the ping cadence:
     * two full missed-ping cycles of grace beyond the one expected answer
     * (hub SyncPlayRelayWorker.php LIVENESS_REAP_AFTER_SILENCE_SECONDS
     * docblock: "the same 3x margin").
     */
    public const int MIN_MARGIN_FACTOR = 3;

    private function __construct(
        private readonly int $pingIntervalSeconds,
        private readonly int $reapAfterSilenceSeconds,
    ) {
    }

    /**
     * Build a validated liveness window pair.
     *
     * @param int $pingIntervalSeconds     Seconds between keepalive pings.
     * @param int $reapAfterSilenceSeconds Seconds of inbound silence before a
     *                                     peer is reaped.
     *
     * @throws InvalidArgumentException when either value is below one second.
     */
    public static function new(int $pingIntervalSeconds, int $reapAfterSilenceSeconds): self
    {
        if ($pingIntervalSeconds < 1) {
            throw new InvalidArgumentException(
                'Liveness ping interval must be at least 1 second, got ' . $pingIntervalSeconds . '.'
            );
        }

        if ($reapAfterSilenceSeconds < 1) {
            throw new InvalidArgumentException(
                'Liveness reap-after-silence window must be at least 1 second, got '
                . $reapAfterSilenceSeconds . '.'
            );
        }

        return new self($pingIntervalSeconds, $reapAfterSilenceSeconds);
    }

    /** Keepalive cadence, in seconds, as validated at construction. */
    public function pingIntervalSeconds(): int
    {
        return $this->pingIntervalSeconds;
    }

    /** Inbound-silence reap window, in seconds, as validated at construction. */
    public function reapAfterSilenceSeconds(): int
    {
        return $this->reapAfterSilenceSeconds;
    }

    /**
     * Halt loudly when a tuning mis-couples the pair: the reap window must
     * keep at least MIN_MARGIN_FACTOR times the ping cadence of headroom,
     * or quiet-but-live peers are reaped as ghosts (HB-0.1).
     *
     * @throws InvalidArgumentException when reapAfterSilenceSeconds is below
     *                                  3x the ping interval.
     */
    public function assertMargin(): void
    {
        $minimum = $this->pingIntervalSeconds * self::MIN_MARGIN_FACTOR;

        if ($this->reapAfterSilenceSeconds < $minimum) {
            throw new InvalidArgumentException(sprintf(
                'Liveness reap window %ds breaks the %dx margin: ping interval %ds needs a '
                . 'reap-after-silence of at least %ds '
                . '(two full missed-ping cycles of grace for a slow reconnect-free network).',
                $this->reapAfterSilenceSeconds,
                self::MIN_MARGIN_FACTOR,
                $this->pingIntervalSeconds,
                $minimum
            ));
        }
    }
}
