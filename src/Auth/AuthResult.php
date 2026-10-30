<?php

/**
 * Auth Result.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Auth;

use InvalidArgumentException;

/**
 * Immutable value object returned by {@see ProviderInterface::authenticate()}.
 *
 * Captures the outcome of an authentication attempt against an external
 * provider. On success with a resolved account, the caller uses userId to
 * issue a local JWT; external-provider successes may legitimately carry
 * userId === null, in which case the caller maps externalId onto (or
 * creates) the local account through the linking flow before issuing.
 *
 * @package Phlix\Shared\Auth
 * @since 0.3.0
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
 * ```
 */
final readonly class AuthResult
{
    /**
     * @param bool                          $success    True when authentication succeeded.
     * @param string|null                   $userId     Local Phlix user UUID — set by
     *     identity providers that resolve straight to an account; null on failures and,
     *     by design, on external-provider successes whose local account is matched
     *     later by the linking flow (see phlix-server's account-link controller).
     * @param string|null                   $externalId Provider-specific ID (null on failure).
     * @param string|null                   $error      Machine-readable error code (null on success).
     * @param array<string, mixed>         $attributes Arbitrary provider-returned claims
     *                                                   (email, name, avatarUrl, etc.).
     *
     * @throws InvalidArgumentException When a "success" result also carries an error.
     */
    public function __construct(
        public bool $success,
        public ?string $userId = null,
        public ?string $externalId = null,
        public ?string $error = null,
        public array $attributes = [],
    ) {
        // Fail-fast at the construction boundary (the package's PayloadAssert
        // doctrine): "success with an error attached" is not a state any
        // consumer may meaningfully handle. Note the inverse is deliberately
        // NOT constrained — external-provider successes legitimately carry
        // userId === null until the linking flow maps the account.
        if ($success && $error !== null) {
            throw new InvalidArgumentException(
                'AuthResult cannot carry an error on success (success and error are mutually exclusive).'
            );
        }
    }

    /**
     * Return true when the authentication attempt succeeded.
     *
     * Convenience alias of the canonical read, {@see self::$success}.
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
     * Convenience alias of the canonical read, {@see self::$success}.
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
