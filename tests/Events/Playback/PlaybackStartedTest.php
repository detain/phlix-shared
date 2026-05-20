<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Events\Playback;

use Phlix\Shared\Events\AbstractEvent;
use Phlix\Shared\Events\Playback\PlaybackPaused;
use Phlix\Shared\Events\Playback\PlaybackResumed;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Events\Playback\PlaybackStarted
 * @covers \Phlix\Shared\Events\Playback\PlaybackPaused
 * @covers \Phlix\Shared\Events\Playback\PlaybackResumed
 * @covers \Phlix\Shared\Events\Playback\PlaybackStopped
 */
final class PlaybackStartedTest extends TestCase
{
    public function test_playback_started_populates_readonly_props(): void
    {
        $event = new PlaybackStarted('s', 'u', 'm', 'd', 12345);

        $this->assertInstanceOf(AbstractEvent::class, $event);
        $this->assertSame('s', $event->sessionId);
        $this->assertSame('u', $event->userId);
        $this->assertSame('m', $event->mediaItemId);
        $this->assertSame('d', $event->deviceId);
        $this->assertSame(12345, $event->positionTicks);
        $this->assertGreaterThan(0, $event->timestamp);
    }

    public function test_playback_paused_populates_readonly_props(): void
    {
        $event = new PlaybackPaused('s', 'u', 'm', 'd', 100);
        $this->assertSame(100, $event->positionTicks);
        $this->assertInstanceOf(AbstractEvent::class, $event);
    }

    public function test_playback_resumed_populates_readonly_props(): void
    {
        $event = new PlaybackResumed('s', 'u', 'm', 'd', 200);
        $this->assertSame(200, $event->positionTicks);
        $this->assertInstanceOf(AbstractEvent::class, $event);
    }

    public function test_playback_stopped_records_completion(): void
    {
        $event = new PlaybackStopped('s', 'u', 'm', 'd', 999, true);

        $this->assertInstanceOf(AbstractEvent::class, $event);
        $this->assertSame(999, $event->finalPositionTicks);
        $this->assertTrue($event->reachedEnd);
    }
}
