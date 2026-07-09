<?php

/**
 * Auth Result.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Auth;

/**
 * Immutable value object returned by {@see ProviderInterface::authenticate()}.
 *
 * Captures the outcome of an authentication attempt against an external
 * provider. On success, the caller uses userId to issue a local JWT and
 * stores externalId on the user row for future lookups.
 *
 * @package Phlix\Shared\Auth
 * @author Phlix Team
 * @version 1.0.0
 * @description Result of an external provider authentication attempt.
 *
 * @see ProviderInterface::authenticate() Where this object is returned.
 * @see UserInfo The user info object that describes the authenticated identity.
 *
 * @example
 * ```php
 * // Success case
 * $result = new AuthResult(
 *     success:    true,
 *     userId:     '550e8400-e29b-41d4-a716-446655440000',
 *     externalId: 'https://accounts.google.com/12345',
 *     attributes: ['email' => 'alice@example.com', 'name' => 'Alice']
 * );
 *
 * // Failure case
 * $result = new AuthResult(
 *     success: false,
 *     error:   'token_expired',
 * );
 * );
 * ```
 */
final readonly class AuthResult
{
    /**
     * @param bool                          $success    True when authentication succeeded.
     * @param string|null                   $userId     Local Phlix user UUID (null on failure).
     * @param string|null                   $externalId Provider-specific ID (null on failure).
     * @param string|null                   $error      Machine-readable error code (null on success).
     * @param array<string, mixed>         $attributes Arbitrary provider-returned claims
     *                                                   (email, name, avatarUrl, etc.).
     */
    public function __construct(
        public bool $success,
        public ?string $userId = null,
        public ?string $externalId = null,
        public ?string $error = null,
        public array $attributes = [],
    ) {
    }

    /**
     * Return true when the authentication attempt succeeded.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Return true when the authentication attempt failed.
     *
     * @return bool
     */
    public function isFailure(): bool
    {
        return !$this->success;
    }

    /**
     * Convenience: return the email from attributes, if present.
     *
     * @return string|null
     */
    public function getEmail(): ?string
    {
        /** @var mixed $email */
        $email = $this->attributes['email'] ?? null;

        return is_string($email) ? $email : null;
    }

    /**
     * Convenience: return the display name from attributes, if present.
     *
     * @return string|null
     */
    public function getDisplayName(): ?string
    {
        /** @var mixed $name */
        $name = $this->attributes['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * Convenience: return the avatar URL from attributes, if present.
     *
     * @return string|null
     */
    public function getAvatarUrl(): ?string
    {
        /** @var mixed $avatarUrl */
        $avatarUrl = $this->attributes['avatarUrl'] ?? null;

        return is_string($avatarUrl) ? $avatarUrl : null;
    }
}
