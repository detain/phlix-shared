<?php

/**
 * Family Grace.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Auth;

/**
 * The pure arithmetic leg of rotation-grace adjudication (M-05).
 *
 * The decision table in both daemons reads: a consumed grant replayed
 * INSIDE the grace window is a benign multi-tab race; a replay OUTSIDE it is
 * token reuse and revokes the family. The SQL arms of that table
 * (`consumed_at >= DATE_SUB(NOW(), INTERVAL g SECOND)`, per-store bind
 * dialect) stay in the repos — SQL never ships in shared. This is the same
 * comparison over already-read integers, single-sourced so the time leg of
 * the decision cannot drift between a repository's SQL and a manager's
 * in-memory re-check.
 *
 * Clock-skew posture matches the SQL law exactly: a `consumedAt` in the
 * future (clock moved back after the write) satisfies `now - consumedAt <= g`
 * just as it satisfies the DATE_SUB comparison — inside grace, never an
 * out-of-grace false on fresh writes.
 *
 * @package Phlix\Shared\Auth
 * @since 0.56.0
 */
final class FamilyGrace
{
    /**
     * Prevent instantiation — this class is a static utility only.
     */
    private function __construct()
    {
    }

    /**
     * Whether `$now` falls within the grace window of a grant consumed at `$consumedAt`.
     *
     * @param int      $consumedAt UNIX seconds of the consumption write (store clock).
     * @param int      $now        UNIX seconds of the replay attempt (same basis).
     * @param int|null $grace      Window in seconds; null (or the caller's absent
     *                             store field) selects the family default,
     *                             {@see SessionFamily::ROTATION_GRACE_SECONDS}.
     */
    public static function withinGrace(
        int $consumedAt,
        int $now,
        ?int $grace = SessionFamily::ROTATION_GRACE_SECONDS
    ): bool {
        $window = $grace ?? SessionFamily::ROTATION_GRACE_SECONDS;

        return $now - $consumedAt <= $window;
    }
}
