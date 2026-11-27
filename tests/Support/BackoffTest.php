<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Support;

use Phlix\Shared\Support\Backoff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Backoff Math - the Stepped Ladder and the Jitter Spread Bounds
 *
 * Pins the curves against the live parents' arithmetic (DESIGN M-15 / C-08):
 * the hub ladder's doubling sequence (5,10,20,40,60-cap) and the server
 * relay consumer's `1 +/- jitterRatio` multiplier around the same exponential
 * - with the random draw behind the seam so the spread's EDGES are testable.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 * @package   Phlix\Shared\Tests\Support
 * @since     0.56.0
 */
#[CoversClass(Backoff::class)]
final class BackoffTest extends TestCase
{
    public function test_stepped_reproduces_the_hub_federation_ladder(): void
    {
        // hub FederationPeerManager: start 5, double, cap 60 (the ENABLED-path
        // ladder - park-at-cap state stays repo-side).
        self::assertSame(5, Backoff::stepped(0, 5, 60));
        self::assertSame(10, Backoff::stepped(1, 5, 60));
        self::assertSame(20, Backoff::stepped(2, 5, 60));
        self::assertSame(40, Backoff::stepped(3, 5, 60));
        self::assertSame(60, Backoff::stepped(4, 5, 60)); // 80 capped
        self::assertSame(60, Backoff::stepped(30, 5, 60)); // far out, still capped
    }

    public function test_stepped_reproduces_the_server_relay_exponential_before_jitter(): void
    {
        // server RelayConsumer: min(base * 2^attempts, max) with 5 -> 300.
        self::assertSame(5, Backoff::stepped(0, 5, 300));
        self::assertSame(320, Backoff::stepped(6, 5, 1000));
        self::assertSame(300, Backoff::stepped(20, 5, 300));
    }

    public function test_stepped_honors_a_custom_factor(): void
    {
        self::assertSame(3, Backoff::stepped(0, 3, 1000, 3));
        self::assertSame(27, Backoff::stepped(2, 3, 1000, 3)); // 3 * 3^2
        self::assertSame(81, Backoff::stepped(3, 3, 1000, 3)); // 3 * 3^3
    }

    public function test_stepped_defends_negative_attempts_and_cap_below_base(): void
    {
        self::assertSame(5, Backoff::stepped(-4, 5, 60));
        self::assertSame(2, Backoff::stepped(3, 5, 2)); // cap wins immediately
        self::assertSame(0, Backoff::stepped(3, 5, 0)); // zero cap is honored
    }

    public function test_stepped_never_overflows_into_nonsense_for_absurd_attempts(): void
    {
        // 2**1024 would go INF; the guard pins the cap instead.
        self::assertSame(60, Backoff::stepped(1024, 5, 60));
    }

    public function test_jitter_edges_are_the_declared_spread(): void
    {
        // Server math: exponential * (1 + ratio * ((rand/max)*2 - 1)).
        // rng()=0 pins the low edge, 1 the high edge, 0.5 the center.
        $low = static fn (): float => 0.0;
        $high = static fn (): float => 1.0;
        $center = static fn (): float => 0.5;

        // attempt 2, base 5 -> exponential 20; ratio .3 -> 14 / 20 / 26.
        self::assertSame(14, Backoff::jittered(2, 5, 300, 0.3, $low));
        self::assertSame(20, Backoff::jittered(2, 5, 300, 0.3, $center));
        self::assertSame(26, Backoff::jittered(2, 5, 300, 0.3, $high));
    }

    public function test_jitter_at_the_cap_edge_still_respects_the_low_multiplier(): void
    {
        $low = static fn (): float => 0.0;

        // capped at 60 * 0.7 = 42 - jitter pulls BELOW the cap by design
        // (the cap is on the exponential, not the jittered answer).
        self::assertSame(42, Backoff::jittered(10, 5, 60, 0.3, $low));
    }

    public function test_jitter_zero_ratio_collapses_to_stepped(): void
    {
        $rng = static fn (): float => 0.123456789;

        self::assertSame(80, Backoff::jittered(4, 5, 1000, 0.0, $rng));
    }

    public function test_default_rng_stays_within_the_declared_spread(): void
    {
        $center = Backoff::stepped(3, 5, 300); // 40

        for ($i = 0; $i < 200; $i++) {
            $j = Backoff::jittered(3, 5, 300);

            // +-0.3 of 40 = [28, 52]; the int cast can trim to 28.
            self::assertGreaterThanOrEqual(27, $j);
            self::assertLessThanOrEqual(53, $j);
        }

        self::assertSame(40, $center);
    }

    public function test_jitter_answers_whole_seconds(): void
    {
        // attempt 1, base 5 -> exponential 10; rng .999 -> 10 * 1.2994 =
        // 12.994 -> rounds to the whole second 13.
        $halfUp = static fn (): float => 0.999;

        self::assertSame(13, Backoff::jittered(1, 5, 60, 0.3, $halfUp));
    }

    public function test_next_stepped_reproduces_the_hub_park_at_cap_ladder(): void
    {
        // The hub's byte-pinned reconnect curve: state starts at 5 and
        // every wake doubles it, parked at 60 — expressed kernel-side.
        $ladder = [];
        $delay = 5;
        for ($tick = 0; $tick < 6; $tick++) {
            $ladder[] = $delay = Backoff::nextStepped($delay, 2, 60);
        }

        self::assertSame([10, 20, 40, 60, 60, 60], $ladder);
    }

    public function test_next_stepped_honors_a_custom_factor(): void
    {
        self::assertSame(15, Backoff::nextStepped(5, 3, 100));
        self::assertSame(45, Backoff::nextStepped(15, 3, 100));
        self::assertSame(100, Backoff::nextStepped(45, 3, 100), '45 * 3 = 135 parks at the cap');
    }

    public function test_next_stepped_parks_when_state_already_exceeds_the_cap(): void
    {
        self::assertSame(60, Backoff::nextStepped(100, 2, 60));
        self::assertSame(60, Backoff::nextStepped(60, 2, 60));
    }

    public function test_next_stepped_degenerate_states_yield_no_wait(): void
    {
        // Mirrors stepped()'s attempt < 0 -> 0 convention: a state that
        // carries no delay, no multiplier, or no ceiling manufactures none.
        self::assertSame(0, Backoff::nextStepped(0, 2, 60));
        self::assertSame(0, Backoff::nextStepped(-5, 2, 60));
        self::assertSame(0, Backoff::nextStepped(5, 0, 60));
        self::assertSame(0, Backoff::nextStepped(5, -2, 60));
        self::assertSame(0, Backoff::nextStepped(5, 2, 0));
        self::assertSame(0, Backoff::nextStepped(5, 2, -60));
    }

    public function test_next_stepped_overflow_saturates_to_the_cap(): void
    {
        // The doubled product widens to float and simply exceeds the
        // ceiling; the answer is the cap, never a negative int cast.
        self::assertSame(300, Backoff::nextStepped(PHP_INT_MAX, 2, 300));
        self::assertSame(60, Backoff::nextStepped(intdiv(PHP_INT_MAX, 2) + 3, 2, 60));
    }
}
