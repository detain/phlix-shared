<?php

/**
 * SyncPlay Dialect.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Relay;

/**
 * Canonical SyncPlay wire message-type catalog.
 *
 * The same nineteen message types have been transcribed independently in
 * three places: the server's `Phlix\Session\SyncPlay\Messages` constants,
 * the hub's `SyncPlayRelayWorker` inline literals, and the
 * `@phlix/syncplay` package's `messages.ts`. The three-way set difference
 * is empty today, but every leg keeps its own copy — a rename in one
 * surfaces as a runtime protocol mismatch, not as a red drift test.
 * This class is the SSOT: the canonical
 * values, with const NAMES mirroring the server's so consumers can alias
 * `public const TYPE_CHAT_MESSAGE = SyncPlayDialect::TYPE_CHAT_MESSAGE;`
 * with zero wire-behavior change.
 *
 * Boundary: this catalog covers only the canonical `syncplay_`-prefixed
 * message types. The hub's bare relay-local family — `room_state`,
 * `client_joined`, `client_left`, `time_sync_reply` — belongs to the
 * hub's own relay protocol, not to SyncPlay, and deliberately does not
 * live here.
 *
 * Enforcement ships with this package: `tests/Relay/SyncPlayDialectTest.php`
 * pins the catalog intrinsically, parses the server and hub siblings live
 * (token-based; skipped when no sibling checkout is reachable, failing
 * loudly when a `PHLIX_SERVER_REPO`/`PHLIX_HUB_REPO` pointer is set but
 * broken), and carries fixture pins so the guard cannot pass vacuously
 * where CI checks out only this repository. The consumer-side legs — the
 * hub test import, the server constant aliases and the syncplay package —
 * belong to those repos.
 *
 * @package Phlix\Shared
 * @since 0.55.0
 */
final class SyncPlayDialect
{
    /** Prefix shared by every canonical SyncPlay wire message type. */
    public const WIRE_PREFIX = 'syncplay_';

    /** Message type: create a new SyncPlay group. */
    public const TYPE_GROUP_CREATE = 'syncplay_group_create';

    /** Message type: join an existing SyncPlay group. */
    public const TYPE_GROUP_JOIN = 'syncplay_group_join';

    /** Message type: leave the current SyncPlay group. */
    public const TYPE_GROUP_LEAVE = 'syncplay_group_leave';

    /** Message type: full group state broadcast (from host). */
    public const TYPE_GROUP_STATE = 'syncplay_group_state';

    /** Message type: request the list of available groups. */
    public const TYPE_GROUP_LIST = 'syncplay_group_list';

    /** Message type: start or resume playback. */
    public const TYPE_PLAYBACK_PLAY = 'syncplay_playback_play';

    /** Message type: pause playback. */
    public const TYPE_PLAYBACK_PAUSE = 'syncplay_playback_pause';

    /** Message type: seek to a new position. */
    public const TYPE_PLAYBACK_SEEK = 'syncplay_playback_seek';

    /** Message type: update the playback queue. */
    public const TYPE_PLAYBACK_QUEUE = 'syncplay_playback_queue';

    /** Message type: periodic playback sync broadcast. */
    public const TYPE_PLAYBACK_SYNC = 'syncplay_playback_sync';

    /** Message type: chat message. */
    public const TYPE_CHAT_MESSAGE = 'syncplay_chat';

    /** Message type: typing indicator. */
    public const TYPE_CHAT_TYPING = 'syncplay_typing';

    /** Message type: voluntary host transfer. */
    public const TYPE_HOST_TRANSFER = 'syncplay_host_transfer';

    /** Message type: automatic host election. */
    public const TYPE_HOST_ELECT = 'syncplay_host_elect';

    /** Message type: time-sync ping (client → server). */
    public const TYPE_TIME_PING = 'syncplay_time_ping';

    /** Message type: time-sync pong (server → client). */
    public const TYPE_TIME_PONG = 'syncplay_time_pong';

    /** Message type: full time-sync state broadcast. */
    public const TYPE_TIME_SYNC = 'syncplay_time_sync';

    /** Message type: error notification. */
    public const TYPE_ERROR = 'syncplay_error';

    /** Message type: general informational message. */
    public const TYPE_INFO = 'syncplay_info';

    /**
     * Every canonical message type, in the server's declaration order.
     *
     * @var list<string>
     */
    private const TYPES = [
        self::TYPE_GROUP_CREATE,
        self::TYPE_GROUP_JOIN,
        self::TYPE_GROUP_LEAVE,
        self::TYPE_GROUP_STATE,
        self::TYPE_GROUP_LIST,
        self::TYPE_PLAYBACK_PLAY,
        self::TYPE_PLAYBACK_PAUSE,
        self::TYPE_PLAYBACK_SEEK,
        self::TYPE_PLAYBACK_QUEUE,
        self::TYPE_PLAYBACK_SYNC,
        self::TYPE_CHAT_MESSAGE,
        self::TYPE_CHAT_TYPING,
        self::TYPE_HOST_TRANSFER,
        self::TYPE_HOST_ELECT,
        self::TYPE_TIME_PING,
        self::TYPE_TIME_PONG,
        self::TYPE_TIME_SYNC,
        self::TYPE_ERROR,
        self::TYPE_INFO,
    ];

    /**
     * The canonical catalog, in server-declaration order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return self::TYPES;
    }

    /**
     * Whether a raw wire string names a canonical SyncPlay message type.
     */
    public static function isValidType(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    /**
     * Prevent instantiation — static catalog only.
     *
     * @internal
     */
    private function __construct()
    {
    }
}
