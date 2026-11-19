<?php

/**
 * LivenessWindows Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use InvalidArgumentException;
use Phlix\Shared\Relay\LivenessWindows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Characterization of the executable 3x liveness-margin law (v0.57 leg 0.11,
 * M-22 / C-09). The law exists as prose on three estate surfaces — hub
 * src/SyncPlay/SyncPlayRelayWorker.php LIVENESS_PING_INTERVAL_SECONDS=30 /
 * LIVENESS_REAP_AFTER_SILENCE_SECONDS=90 (":90 against the 30s
 * cadence is the same 3x margin"), hub src/Relay/IdleReaper.php
 * DEFAULT_STALE_THRESHOLD_SECONDS=90 coupled to the server default ping of 30
 * ("The default 90s >> 30s gives ~3 heartbeats of headroom"), and server
 * src/Hub/RelayConfig.php envInt PHLIX_RELAY_PING_INTERVAL 30. The shipped
 * true ping/reap pairs on that axis all satisfy reap >= 3x ping; the VO now
 * encodes it once.
 */
#[CoversClass(LivenessWindows::class)]
final class LivenessWindowsTest extends TestCase
{
    public function testShippedHubWorkerPairIsExactlyThreeTimesAndPasses(): void
    {
        $windows = LivenessWindows::new(30, 90);

        self::assertSame(30, $windows->pingIntervalSeconds());
        self::assertSame(90, $windows->reapAfterSilenceSeconds());

        $windows->assertMargin();
    }

    public function testIdleReaperWindowAgainstServerDefaultPingPasses(): void
    {
        // IdleReaper's stale threshold (90) couples to the SERVER relay ping
        // (30), not to its own 60s scan interval — hub IdleReaper.php :47-52
        // docblock. Same 3x-exact pair on the true axis.
        $windows = LivenessWindows::new(30, 90);
        $windows->assertMargin();

        self::assertGreaterThanOrEqual(
            LivenessWindows::MIN_MARGIN_FACTOR * $windows->pingIntervalSeconds(),
            $windows->reapAfterSilenceSeconds()
        );
    }

    public function testOneSecondBelowTheMarginThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/89/');

        LivenessWindows::new(30, 89)->assertMargin();
    }

    public function testStrictlyGreaterButBelowMarginStillThrows(): void
    {
        // The prose MUST is "strictly greater"; the executable law is the
        // stricter 3x headroom the prose then demands ("keep that margin if
        // either value is tuned"). 60 > 30 but 60 < 3x30 — the ghost-reap
        // class the headroom exists to prevent.
        $this->expectException(InvalidArgumentException::class);

        LivenessWindows::new(30, 60)->assertMargin();
    }

    public function testArbitraryPairAtExactBoundaryPasses(): void
    {
        $windows = LivenessWindows::new(60, 180);
        $windows->assertMargin();

        self::assertSame(180, 3 * $windows->pingIntervalSeconds());
    }

    public function testArbitraryPairJustBelowBoundaryThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LivenessWindows::new(60, 179)->assertMargin();
    }

    public function testMarginFactorConstantIsThree(): void
    {
        self::assertSame(3, LivenessWindows::MIN_MARGIN_FACTOR);
    }

    #[DataProvider('nonPositiveSecondCases')]
    public function testNonPositiveSecondsAreRefusedAtConstruction(int $ping, int $reap): void
    {
        // Fail-fast: a zero/negative second makes the margin law
        // meaningless (server RelayConfig's "< 1 disables the watchdog" is
        // per-repo watchdog semantics, not a window the VO can model).
        $this->expectException(InvalidArgumentException::class);

        LivenessWindows::new($ping, $reap);
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function nonPositiveSecondCases(): array
    {
        return [
            'zero ping' => [0, 90],
            'negative ping' => [-1, 90],
            'zero reap' => [30, 0],
            'negative reap' => [30, -5],
        ];
    }

    public function testPingFrameConstantIsDeliberatelyAbsent(): void
    {
        // Charter C-09 negative law: the ping frame byte is codec territory
        // (FrameCodec), NOT the liveness VO. Pinned so a future "convenience"
        // lift into this class reddens.
        $constants = (new ReflectionClass(LivenessWindows::class))->getConstants();

        self::assertArrayNotHasKey('PING_FRAME', $constants);
    }
}
