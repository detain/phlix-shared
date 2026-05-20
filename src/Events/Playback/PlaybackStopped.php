<?php

declare(strict_types=1);

namespace Phlix\Shared\Events\Playback;

use Phlix\Shared\Events\AbstractEvent;

/**
 * Fired when a playback session ends, either at user request or end-of-media.
 *
 * Fired by: `\Phlix\Session\PlaybackController::markAsWatched()` and the
 * `clearProgress()` lifecycle hook.
 * Typical listener: scrobble plugins (final scrobble), watch-history
 * "complete" markers, recommendation refreshers, post-credits skip
 * removers, smart-home "movie mode" deactivators.
 *
 * Manifest alias: `phlix.playback.stopped`.
 *
 * @package Phlix\Shared\Events\Playback
 * @since 0.2.0
 */
final class PlaybackStopped extends AbstractEvent
{
    /**
     * @param string $sessionId          UUID of the playback session.
     * @param string $userId             UUID of the user driving the session.
     * @param string $mediaItemId        UUID of the media item being played.
     * @param string $deviceId           Stable identifier of the client device.
     * @param int    $finalPositionTicks Final playback position when the
     *                                   session ended, in 100-ns ticks.
     * @param bool   $reachedEnd         True when the listener should treat
     *                                   the item as fully watched (>= 90 %
     *                                   per Phlix convention); false when
     *                                   the user stopped mid-stream.
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $userId,
        public readonly string $mediaItemId,
        public readonly string $deviceId,
        public readonly int $finalPositionTicks,
        public readonly bool $reachedEnd,
    ) {
        parent::__construct();
    }
}
