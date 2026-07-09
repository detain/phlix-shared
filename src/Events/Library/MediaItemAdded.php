<?php

/**
 * Media Item Added.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Events\Library;

use Phlix\Shared\Events\AbstractEvent;

/**
 * Fired when a new media item is added to a library.
 *
 * Fired by: `\Phlix\Media\Library\MediaScanner::processFile()` after a
 * previously-unseen file is successfully persisted via `ItemRepository`.
 * Typical listener: metadata-refresh queue worker (kick off TMDb /
 * MusicBrainz lookup), "what's new" notifier, recommendation index
 * updater, intro-detection job queuer.
 *
 * Manifest alias: `phlix.library.item.added`.
 *
 * @package Phlix\Shared\Events\Library
 * @since 0.2.0
 */
final class MediaItemAdded extends AbstractEvent
{
    /**
     * @param string $mediaItemId UUID of the newly-persisted media item.
     * @param string $libraryId   UUID of the library it was added to.
     * @param string $path        Absolute filesystem path of the source
     *                            file.
     * @param string $type        Item type — one of `movie`, `episode`,
     *                            `track`, `image`, `book`, `audiobook`,
     *                            depending on the library.
     */
    public function __construct(
        public readonly string $mediaItemId,
        public readonly string $libraryId,
        public readonly string $path,
        public readonly string $type,
    ) {
        parent::__construct();
    }
}
