<?php

declare(strict_types=1);

namespace Phlix\Shared\Events\Library;

use Phlix\Shared\Events\AbstractEvent;

/**
 * Fired when a library scan finishes.
 *
 * Fired by: `\Phlix\Media\Library\MediaScanner::scan()` after the
 * recursive walk completes, with the final tally of added / updated /
 * removed items and the wall-clock duration.
 * Typical listener: webhook notification framework ("scan complete"),
 * dashboard refreshers, recommendation cache invalidators, "what's new"
 * digest mailers.
 *
 * Manifest alias: `phlix.library.scan.completed`.
 *
 * @package Phlix\Shared\Events\Library
 * @since 0.2.0
 */
final class LibraryScanCompleted extends AbstractEvent
{
    /**
     * @param string $libraryId    UUID of the library that was scanned.
     * @param int    $itemsAdded   New media items discovered and persisted.
     * @param int    $itemsUpdated Existing items whose metadata was refreshed.
     * @param int    $itemsRemoved Items removed because the backing file
     *                             vanished between scans.
     * @param int    $durationMs   Wall-clock duration of the scan in
     *                             milliseconds.
     */
    public function __construct(
        public readonly string $libraryId,
        public readonly int $itemsAdded,
        public readonly int $itemsUpdated,
        public readonly int $itemsRemoved,
        public readonly int $durationMs,
    ) {
        parent::__construct();
    }
}
