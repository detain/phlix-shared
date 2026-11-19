<?php

/**
 * Family Refusal Reason.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Auth;

/**
 * Audit-vocabulary reasons a refresh-family request is refused (M-05).
 *
 * Eight arms — the server's adjudication ladder, values are the server's
 * own literals because those strings are the audit vocabulary the admin UIs
 * key on; the migration replaces the literals with `->value` reads, byte for
 * byte. The hub's collapsed refusal *text* on the wire is an intentional
 * wire-law (B-04) and is kept: the hub adopts these cases as internal
 * arm-identity, never as user-visible strings.
 *
 * @package Phlix\Shared\Auth
 * @since 0.56.0
 */
enum FamilyRefusalReason: string
{
    /** Session store unwired — fail-closed before any grant lookups. */
    case StoreUnwired = 'token_store_unwired';

    /** Refresh token presented without the family (jti) claim. */
    case GrantJtiMissing = 'refresh_grant_jti_missing';

    /** Grant not found: unknown, already family-revoked, or never minted. */
    case GrantUnknown = 'refresh_grant_unknown';

    /** The bound session row is revoked (status check, grant untouched). */
    case SessionRevoked = 'token_session_revoked';

    /** Grant's session id does not match the token's bound session. */
    case GrantSessionMismatch = 'refresh_grant_session_mismatch';

    /**
     * The atomic consume write lost the race (SRV-S16): a concurrent refresh
     * of the same grant won `consumed_at IS NULL` first — never a mint.
     */
    case GrantConsumeLost = 'refresh_grant_consume_lost';

    /** The profile the refresh is bound to is no longer owned by the caller. */
    case ProfileNotOwned = 'profile_not_owned';

    /** Replay of a consumed grant outside the rotation grace window — reuse. */
    case TokenReuse = 'refresh_token_reuse';
}
