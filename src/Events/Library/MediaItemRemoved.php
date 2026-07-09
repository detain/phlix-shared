<?php

/**
 * Media Item Removed.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Events\Library;

use Phlix\Shared\Events\AbstractEvent;

/**
 * Fired when a media item is removed from a library.
 *
 * Fired by: cleanup passes inside `\Phlix\Media\Library\ItemRepository`
 * and `MediaScanner` when the backing file is gone.
 * Typical listener: search-index cleaner, "your file is gone" notifier,
 * watch-history archiver, recommendation cache invalidator.
 *
 * Manifest alias: `phlix.library.item.removed`.
 *
 * @package Phlix\Shared\Events\Library
 * @since 0.2.0
 */
final class MediaItemRemoved extends AbstractEvent
{
    /**
     * @param string $mediaItemId UUID of the removed item.
     * @param string $libraryId   UUID of the library it was removed from.
     */
    public function __construct(
        public readonly string $mediaItemId,
        public readonly string $libraryId,
    ) {
        parent::__construct();
    }
}
