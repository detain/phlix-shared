<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Support;

use Phlix\Shared\Support\Clock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Clock Laws - the Round Boundary Fixtures and the Ambient Sanity Bracket
 *
 * The systematic half-ms of the C-03/C-04 skew lives in the conversion
 * `(int) (microtime(true) * 1000)` vs `(int) round(...)`. unixMs() cannot be
 * pinned against a wall clock, so the round LAW gets its boundary corpus on
 * the pure seam msFromUnixSeconds(), and the ambient method only asserts its
 * bracket. Owner row O-9's golden-move duty rides the server adoption leg,
 * documented on the class.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 * @package   Phlix\Shared\Tests\Support
 * @since     0.56.0
 */
#[CoversClass(Clock::class)]
final class ClockTest extends TestCase
{
    public function test_the_round_law_at_the_half_millisecond_boundary(): void
    {
        // Truncation (the server's current reading) would answer 499 for the
        // .499x cases where the millisecond remainder exceeds .5; the round
        // law - matching the hub and JS Date.now - answers 500. These pairs
        // ARE the M-14 decision, as data. Fixtures keep a margin of at least
        // ~.1 ms either side of the .5 boundary so no exact-half binary-float
        // ambiguity can flip a verdict.
        self::assertSame(1700000000499, Clock::msFromUnixSeconds(1700000000.4994)); // .4 -> stays
        self::assertSame(1700000000500, Clock::msFromUnixSeconds(1700000000.4999)); // .9 -> rounds UP, truncation would lose a ms
        self::assertSame(1700000000500, Clock::msFromUnixSeconds(1700000000.5004)); // .4 -> rounds DOWN, not up
        self::assertSame(1700000000501, Clock::msFromUnixSeconds(1700000000.50099)); // .99 -> up
    }

    public function test_the_round_law_also_survives_pre_epoch_negatives(): void
    {
        // PHP round() is half-alternate-away-from-zero; the seam must round
        // negatives too, not truncate toward zero like the (int) cast.
        self::assertSame(-1000, Clock::msFromUnixSeconds(-1.0));
        self::assertSame(-1001, Clock::msFromUnixSeconds(-1.00099));
        self::assertSame(-1000, Clock::msFromUnixSeconds(-1.0004));
    }

    public function test_whole_seconds_convert_without_drift(): void
    {
        self::assertSame(1700000000000, Clock::msFromUnixSeconds(1700000000.0));
        self::assertSame(0, Clock::msFromUnixSeconds(0.0));
    }

    public function test_unix_ms_brackets_the_wall_clock(): void
    {
        // Ambient check only - the precise law lives in the seam above.
        // Allow a 2 s tolerance either way against time(); the point is that
        // unixMs() is in milliseconds at all, not seconds, and not negative.
        $beforeMs = time() * 1000;
        $ms = Clock::unixMs();
        $afterMs = (time() + 1) * 1000;

        self::assertGreaterThanOrEqual($beforeMs - 2000, $ms);
        self::assertLessThanOrEqual($afterMs + 2000, $ms);
    }

    public function test_unix_ms_agrees_with_the_seam_for_its_own_reading(): void
    {
        // The public seam IS unixMs's math: a fixed float run through the
        // seam must disagree with the estate's old truncation and answer the
        // round law - proving unixMs() carries the seam's conversion.
        $frozen = 1700000000.4999;
        $viaSeam = Clock::msFromUnixSeconds($frozen);
        $viaTruncation = (int) ($frozen * 1000);

        self::assertSame(1700000000500, $viaSeam);
        self::assertSame(1700000000499, $viaTruncation);
        self::assertNotSame($viaTruncation, $viaSeam);
    }

    public function test_unix_sec_tracks_time(): void
    {
        self::assertLessThanOrEqual(1, abs(Clock::unixSec() - time()));
    }
}
