<?php

/**
 * Abstract Event Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Events;

use Phlix\Shared\Events\AbstractEvent;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * @covers \Phlix\Shared\Events\AbstractEvent
 */
final class AbstractEventTest extends TestCase
{
    public function test_timestamp_set_at_construction(): void
    {
        $before = time();
        $event = new class () extends AbstractEvent {
        };
        $after = time();

        $this->assertGreaterThanOrEqual($before, $event->timestamp);
        $this->assertLessThanOrEqual($after, $event->timestamp);
    }

    public function test_timestamp_uses_clock_when_provided(): void
    {
        $fixedNow = new class implements ClockInterface {
            private int $fixedTime = 1700000000;

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@' . $this->fixedTime);
            }
        };

        $event = new class ($fixedNow) extends AbstractEvent {
            public function __construct(ClockInterface $clock)
            {
                parent::__construct($clock);
            }
        };

        $this->assertSame(1700000000, $event->timestamp);
    }

    public function test_timestamp_falls_back_to_time_when_no_clock(): void
    {
        $before = time();
        $event = new class () extends AbstractEvent {
            // Explicitly passing null to verify BC fallback
            public function __construct()
            {
                parent::__construct(null);
            }
        };
        $after = time();

        $this->assertGreaterThanOrEqual($before, $event->timestamp);
        $this->assertLessThanOrEqual($after, $event->timestamp);
    }
}
