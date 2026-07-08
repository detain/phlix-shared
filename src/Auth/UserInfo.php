<?php

/**
 * User Info.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Auth;

/**
 * Immutable value object returned by {@see ProviderInterface::getUserInfo()}.
 *
 * Describes an external identity (email, display name, avatar, raw claims)
 * independent of any local Phlix user record. Used for account linking
 * and profile display.
 *
 * @package Phlix\Shared\Auth
 * @author Phlix Team
 * @version 1.0.0
 * @description User information from an external authentication provider.
 *
 * @see ProviderInterface::getUserInfo() Where this object is returned.
 * @see AuthResult The auth result that wraps user info on successful authentication.
 *
 * @example
 * ```php
 * $info = new UserInfo(
 *     externalId:     'https://accounts.google.com/12345',
 *     email:           'alice@example.com',
 *     displayName:     'Alice',
 *     avatarUrl:      'https://lh3.googleusercontent.com/photo.jpg',
 *     rawAttributes: ['sub' => '12345', 'email_verified' => true]
 * );
 * ```
 */
final readonly class UserInfo
{
    /**
     * @param string                $externalId    Provider-specific unique identifier.
     * @param string|null          $email          User's email address (may be null for some providers).
     * @param string|null          $displayName    Human-readable display name.
     * @param string|null          $avatarUrl       URL to the user's avatar / profile picture.
     * @param array<string, mixed>  $rawAttributes   All provider-returned claims as key-value pairs.
     */
    public function __construct(
        public string $externalId,
        public ?string $email = null,
        public ?string $displayName = null,
        public ?string $avatarUrl = null,
        public array $rawAttributes = [],
    ) {
    }

    /**
     * Return true when the external user has an email address on record.
     *
     * @return bool
     */
    public function hasEmail(): bool
    {
        return $this->email !== null;
    }

    /**
     * Return true when the external user has a display name on record.
     *
     * @return bool
     */
    public function hasDisplayName(): bool
    {
        return $this->displayName !== null;
    }

    /**
     * Return true when the external user has an avatar URL on record.
     *
     * @return bool
     */
    public function hasAvatarUrl(): bool
    {
        return $this->avatarUrl !== null;
    }

    /**
     * Return a claim from rawAttributes by name, with optional default.
     *
     * @param string     $name         The claim key.
     * @param mixed      $default      Value to return when the key is absent.
     * @return mixed The claim value or $default.
     */
    public function getClaim(string $name, mixed $default = null): mixed
    {
        return $this->rawAttributes[$name] ?? $default;
    }
}
