<?php

declare(strict_types=1);

namespace Phlex\Shared\Plugin;

use Phlex\Shared\Events\Auth\UserCreated;
use Phlex\Shared\Events\Auth\UserLoggedIn;
use Phlex\Shared\Events\Auth\UserLoggedOut;
use Phlex\Shared\Events\Library\LibraryScanCompleted;
use Phlex\Shared\Events\Library\LibraryScanStarted;
use Phlex\Shared\Events\Library\MediaItemAdded;
use Phlex\Shared\Events\Library\MediaItemRemoved;
use Phlex\Shared\Events\Library\MediaItemUpdated;
use Phlex\Shared\Events\Playback\PlaybackPaused;
use Phlex\Shared\Events\Playback\PlaybackResumed;
use Phlex\Shared\Events\Playback\PlaybackStarted;
use Phlex\Shared\Events\Playback\PlaybackStopped;

/**
 * Static lookup table between manifest event aliases and concrete
 * event-class FQCNs.
 *
 * The manifest format keeps event names short and stable (e.g.
 * `phlex.playback.started`) so plugin authors don't have to type a
 * fully-qualified PHP class name in JSON. At runtime, however, the
 * PSR-14 dispatcher keys subscriptions on the event class, so the
 * plugin loader translates each manifest alias to its FQCN via this
 * map before attaching listeners.
 *
 * One entry per event class published by `phlex-shared` `src/Events/`.
 * The canonical list lives in `docs/dev/event-reference.md` shipped
 * with `phlex-server` — keep this table and the doc in sync.
 *
 * @package Phlex\Shared\Plugin
 * @since 0.2.0
 */
final class EventNameMap
{
    /**
     * Manifest alias -> event class FQCN.
     *
     * @var array<string, class-string>
     */
    private const ALIAS_TO_FQCN = [
        'phlex.playback.started'       => PlaybackStarted::class,
        'phlex.playback.paused'        => PlaybackPaused::class,
        'phlex.playback.resumed'       => PlaybackResumed::class,
        'phlex.playback.stopped'       => PlaybackStopped::class,
        'phlex.library.scan.started'   => LibraryScanStarted::class,
        'phlex.library.scan.completed' => LibraryScanCompleted::class,
        'phlex.library.item.added'     => MediaItemAdded::class,
        'phlex.library.item.updated'   => MediaItemUpdated::class,
        'phlex.library.item.removed'   => MediaItemRemoved::class,
        'phlex.user.created'           => UserCreated::class,
        'phlex.user.logged_in'         => UserLoggedIn::class,
        'phlex.user.logged_out'        => UserLoggedOut::class,
    ];

    /**
     * Prevent instantiation — this class is a static lookup table only.
     */
    private function __construct()
    {
    }

    /**
     * Resolve the event class FQCN for the given manifest alias.
     *
     * @param string $alias Manifest alias such as `phlex.playback.started`.
     *
     * @return class-string|null FQCN when the alias is known, null otherwise.
     *
     * @since 0.2.0
     */
    public static function fromAlias(string $alias): ?string
    {
        return self::ALIAS_TO_FQCN[$alias] ?? null;
    }

    /**
     * Reverse lookup: alias for the given event class FQCN. Mostly useful
     * for debugging output and the doc-generator.
     *
     * @param string $fqcn Event class FQCN.
     *
     * @return string|null Alias when the FQCN is known, null otherwise.
     *
     * @since 0.2.0
     */
    public static function toAlias(string $fqcn): ?string
    {
        $flipped = array_flip(self::ALIAS_TO_FQCN);
        return $flipped[$fqcn] ?? null;
    }

    /**
     * Snapshot of the alias-to-FQCN map. Sorted by alias for stable
     * iteration order in tests and docs.
     *
     * @return array<string, class-string>
     *
     * @since 0.2.0
     */
    public static function aliases(): array
    {
        $map = self::ALIAS_TO_FQCN;
        ksort($map);
        return $map;
    }
}
