<?php

declare(strict_types=1);

namespace Phlix\Shared\Events\Auth;

use Phlix\Shared\Events\AbstractEvent;

/**
 * Fired immediately after a new user account is created.
 *
 * Fired by: `\Phlix\Auth\AuthManager::register()` after the user row is
 * persisted and before the response JWT is generated.
 * Typical listener: welcome-email sender, audit-log writer, default-
 * library-permissions bootstrap, hub-side "user came from server X"
 * mirror.
 *
 * Manifest alias: `phlix.user.created`.
 *
 * @package Phlix\Shared\Events\Auth
 * @since 0.2.0
 */
final class UserCreated extends AbstractEvent
{
    /**
     * @param string $userId   UUID of the freshly-created user row.
     * @param string $username Chosen username (3-50 chars per AuthManager).
     * @param string $email    Validated email address on the account.
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $username,
        public readonly string $email,
    ) {
        parent::__construct();
    }
}
