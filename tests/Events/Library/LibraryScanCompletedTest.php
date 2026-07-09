<?php

/**
 * Library Scan Completed Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Events\Library;

use Phlix\Shared\Events\AbstractEvent;
use Phlix\Shared\Events\Library\LibraryScanCompleted;
use Phlix\Shared\Events\Library\LibraryScanStarted;
use Phlix\Shared\Events\Library\MediaItemAdded;
use Phlix\Shared\Events\Library\MediaItemRemoved;
use Phlix\Shared\Events\Library\MediaItemUpdated;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Events\Library\LibraryScanCompleted
 * @covers \Phlix\Shared\Events\Library\LibraryScanStarted
 * @covers \Phlix\Shared\Events\Library\MediaItemAdded
 * @covers \Phlix\Shared\Events\Library\MediaItemUpdated
 * @covers \Phlix\Shared\Events\Library\MediaItemRemoved
 */
final class LibraryScanCompletedTest extends TestCase
{
    public function test_scan_started_props(): void
    {
        $event = new LibraryScanStarted('lib-id', 'Movies', '/srv/media');

        $this->assertInstanceOf(AbstractEvent::class, $event);
        $this->assertSame('lib-id', $event->libraryId);
        $this->assertSame('Movies', $event->libraryName);
        $this->assertSame('/srv/media', $event->path);
        $this->assertGreaterThan(0, $event->timestamp);
    }

    public function test_scan_completed_records_tally(): void
    {
        $event = new LibraryScanCompleted('lib-id', 5, 2, 1, 12345);

        $this->assertSame('lib-id', $event->libraryId);
        $this->assertSame(5, $event->itemsAdded);
        $this->assertSame(2, $event->itemsUpdated);
        $this->assertSame(1, $event->itemsRemoved);
        $this->assertSame(12345, $event->durationMs);
    }

    public function test_media_item_added_props(): void
    {
        $event = new MediaItemAdded('item', 'lib', '/path/file.mkv', 'movie');
        $this->assertSame('item', $event->mediaItemId);
        $this->assertSame('lib', $event->libraryId);
        $this->assertSame('/path/file.mkv', $event->path);
        $this->assertSame('movie', $event->type);
    }

    public function test_media_item_updated_props(): void
    {
        $event = new MediaItemUpdated('item', ['title', 'overview']);
        $this->assertSame('item', $event->mediaItemId);
        $this->assertSame(['title', 'overview'], $event->changedFields);
    }

    public function test_media_item_removed_props(): void
    {
        $event = new MediaItemRemoved('item', 'lib');
        $this->assertSame('item', $event->mediaItemId);
        $this->assertSame('lib', $event->libraryId);
    }
}
