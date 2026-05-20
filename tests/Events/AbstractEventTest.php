<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Events;

use Phlix\Shared\Events\AbstractEvent;
use PHPUnit\Framework\TestCase;

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
}
