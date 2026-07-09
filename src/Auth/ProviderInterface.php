<?php

/**
 * Oidc Provider.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Auth;

/**
 * Interface for external authentication providers.
 *
 * This interface must be implemented by OIDC, LDAP, SAML, and passkey
 * auth providers. It has ZERO I/O dependencies — no HTTP calls, no DB,
 * no filesystem. All I/O is the responsibility of the concrete
 * implementation (typically a thin adapter in phlix-server that
 * delegates to an external library).
 *
 * @package Phlix\Auth
 * @author Phlix Team
 * @version 1.0.0
 * @description Contract for pluggable external authentication providers.
 *
 * @see AuthResult The result object returned by authenticate().
 * @see UserInfo  The user info object returned by getUserInfo().
 *
 * @example
 * ```php
 * class OidcProvider implements ProviderInterface
 * {
 *     public function name(): string
 *     {
 *         return 'oidc';
 *     }
 *
 *     public function supportsAuthentication(array $credentials): bool
 *     {
 *         return isset($credentials['id_token']) || isset($credentials['code']);
 *     }
 *
 *     public function authenticate(array $credentials): AuthResult { ... }
 *     public function getUserInfo(string $externalId): ?UserInfo { ... }
 *     public function linkAccount(string $localUserId, array $externalIds): void { ... }
 * }
 * ```
 */
interface ProviderInterface
{
    /**
     * Return the provider's unique identifier.
     *
     * Used as the prefix when parsing provider-prefixed usernames
     * (e.g. "oidc:alice@example.com" → provider name = "oidc").
     *
     * @return string Lowercase ASCII identifier, e.g. "oidc", "ldap", "saml", "passkey".
     */
    public function name(): string;

    /**
     * Return true when this provider can handle the given credentials.
     *
     * Used by the caller to decide whether to hand off to this provider
     * or try the next one. Credentials format is provider-specific.
     *
     * @param array<string, mixed> $credentials Provider-specific credential bag.
     * @return bool True when this provider's authenticate() would not immediately fail.
     */
    public function supportsAuthentication(array $credentials): bool;

    /**
     * Authenticate a user with the given credentials.
     *
     * This is the main entry point. Implementations are responsible
     * for all I/O (token validation, userinfo endpoint calls, etc.)
     *
     * @param array<string, mixed> $credentials Provider-specific credential bag.
     * @return AuthResult Success includes userId (local) and externalId (provider-specific).
     */
    public function authenticate(array $credentials): AuthResult;

    /**
     * Look up user information by the provider's external identifier.
     *
     * Used when linking an existing local account to this provider.
     *
     * @param string $externalId The provider-specific user ID.
     * @return UserInfo|null User info when found; null when the external ID is unknown.
     */
    public function getUserInfo(string $externalId): ?UserInfo;

    /**
     * Link an existing local Phlix user account to this provider.
     *
     * Called when a user who already has a local account chooses to
     * connect it to an external identity (e.g. after first login via OIDC).
     * Implementations may store linkage metadata in $externalIds for later
     * use during authentication.
     *
     * @param string $localUserId The local Phlix user UUID.
     * @param array<string, string> $externalIds Map of provider names to their external IDs.
     * @return void
     */
    public function linkAccount(string $localUserId, array $externalIds): void;
}
