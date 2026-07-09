<?php

/**
 * Media Item Updated.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Events\Library;

use Phlix\Shared\Events\AbstractEvent;

/**
 * Fired when an existing media item's metadata changes.
 *
 * Fired by: metadata-refresh writes inside
 * `\Phlix\Media\Library\ItemRepository`.
 * Typical listener: search-index re-indexer, recommendation cache
 * invalidator, "updated metadata" notifier, integrations that mirror
 * Phlix metadata into another tool (Notion, Airtable, etc.).
 *
 * Manifest alias: `phlix.library.item.updated`.
 *
 * @package Phlix\Shared\Events\Library
 * @since 0.2.0
 */
final class MediaItemUpdated extends AbstractEvent
{
    /**
     * @param string                $mediaItemId   UUID of the updated item.
     * @param array<int, string>    $changedFields Ordered list of column /
     *                                             metadata-key names that
     *                                             changed in this update.
     *                                             Listeners can use this to
     *                                             skip work when the change
     *                                             is irrelevant to them.
     */
    public function __construct(
        public readonly string $mediaItemId,
        public readonly array $changedFields,
    ) {
        parent::__construct();
    }
}
