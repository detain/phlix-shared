<?php

/**
 * User Logged In.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Events\Auth;

use Phlix\Shared\Events\AbstractEvent;

/**
 * Fired after a successful login.
 *
 * Fired by: `\Phlix\Auth\AuthManager::login()` after credential
 * verification succeeds and the audit-log entry is written.
 * Typical listener: presence integrations, security-anomaly detector
 * (new IP / new device alerts), device-registry updater, hub session
 * mirror.
 *
 * Manifest alias: `phlix.user.logged_in`.
 *
 * @package Phlix\Shared\Events\Auth
 * @since 0.2.0
 */
final class UserLoggedIn extends AbstractEvent
{
    /**
     * @param string $userId    UUID of the user who logged in.
     * @param string $sessionId UUID / opaque ID identifying the login
     *                          session (in the current AuthManager this is
     *                          the device identifier supplied by the client
     *                          until session rows are added in a later phase).
     * @param string $ipAddress Client IP address as best determined by the
     *                          HTTP layer ("" when not available from the
     *                          calling context — e.g. background jobs).
     * @param string $userAgent Raw HTTP User-Agent string, or "" when not
     *                          available.
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $sessionId,
        public readonly string $ipAddress,
        public readonly string $userAgent,
    ) {
        parent::__construct();
    }
}
