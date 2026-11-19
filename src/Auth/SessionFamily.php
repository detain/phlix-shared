<?php

/**
 * Session Family.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Auth;

/**
 * The session-family wire vocabulary: claim keys and the rotation-grace
 * window shared by the server and hub auth daemons.
 *
 * This is the family/rotation contract both daemons MUST agree on. A refresh
 * token minted by one is adjudicated by the other's session stores against
 * these exact literals (M-04): renaming a claim key here is a coordinated
 * wire change across repos and tag versions — the rename hazard B-02 named,
 * resolved by pinning the vocabulary in one package the consumers alias
 * instead of re-typing. Values are unchanged by the move: the server authored
 * them first (CP-029 lineage); the hub's twins were verified identical.
 *
 * {@see \Phlix\Shared\Auth\JwtClaims::TYPE_ACCESS}/
 * {@see \Phlix\Shared\Auth\JwtClaims::TYPE_REFRESH} already name the token
 * kinds; this class aliases rather than duplicates them so each literal has
 * exactly one definition site.
 *
 * @package Phlix\Shared\Auth
 * @since 0.56.0
 */
final class SessionFamily
{
    /**
     * JWT claim carrying the session (family) id, and the column family the
     * server/hub token-session stores key revocation by.
     */
    public const string CLAIM_SESSION_ID = 'sid';

    /**
     * JWT claim binding an access/refresh token to a profile row (server
     * profile-scoped sessions; the hub's session model has no profile claim).
     */
    public const string CLAIM_PROFILE_ID = 'profile_id';

    /** Access-token type value (alias of the single definition on {@see JwtClaims}). */
    public const TYPE_ACCESS = JwtClaims::TYPE_ACCESS;

    /** Refresh-token type value (alias of the single definition on {@see JwtClaims}). */
    public const TYPE_REFRESH = JwtClaims::TYPE_REFRESH;

    /**
     * Rotation grace window, in seconds.
     *
     * A refresh grant that was consumed within this window may be replayed
     * benignly (multi-tab races); a replay outside it is reuse and revokes
     * the family. Both daemons and {@see FamilyGrace::withinGrace()} read
     * time off their own clocks; the arithmetic lives once, here and there.
     */
    public const int ROTATION_GRACE_SECONDS = 10;

    /**
     * Prevent instantiation — this class is a constant bag only.
     */
    private function __construct()
    {
    }
}
