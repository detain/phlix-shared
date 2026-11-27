<?php

/**
 * CoreUpdateStatus.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Updates;

/**
 * Immutable result of a core (application) update check (v0.57 leg 0.12,
 * M-23 / C-16).
 *
 * Exact twin lift: phlix-server `src/Server/Updates/CoreUpdateStatus.php`
 * (S74) and phlix-hub `src/Hub/Updates/CoreUpdateStatus.php` (S75) — diff
 * proven namespace/prose only. Both hosts render this shape onto
 * `GET /api/v1/admin/updates/status`; field names are camelCase because the
 * shared `@phlix/ui` admin console (the `UpdateAvailableBanner.vue` lineage)
 * consumes both endpoints with ONE parser — the key order in `toArray()` is
 * part of that wire law, not incidental.
 *
 * `updateCommand` is a copy-to-clipboard string ONLY: neither host ever
 * executes it (both twin docblocks state the law; it is preserved here).
 *
 * @since 0.57.0
 */
final class CoreUpdateStatus
{
    /**
     * @param string      $currentVersion  Compiled application version constant.
     * @param string|null $latestVersion   Last successfully fetched marker, or null if never fetched.
     * @param bool        $updateAvailable True when `latestVersion` is strictly newer than `currentVersion`.
     * @param bool        $checkEnabled    Effective `updates.check_enabled`.
     * @param int|null    $lastCheckedAt   Unix timestamp of the last COMPLETED check, or null.
     * @param string|null $lastError       Error from the last failed check, or null when the last check was clean.
     * @param string      $updateCommand   Copy-to-clipboard shell command that performs the update (never executed).
     */
    public function __construct(
        public readonly string $currentVersion,
        public readonly ?string $latestVersion,
        public readonly bool $updateAvailable,
        public readonly bool $checkEnabled,
        public readonly ?int $lastCheckedAt,
        public readonly ?string $lastError,
        public readonly string $updateCommand,
    ) {
    }

    /**
     * JSON-ready payload for the admin status endpoint.
     *
     * @return array{
     *     currentVersion: string,
     *     latestVersion: string|null,
     *     updateAvailable: bool,
     *     checkEnabled: bool,
     *     lastCheckedAt: int|null,
     *     lastError: string|null,
     *     updateCommand: string
     * }
     */
    public function toArray(): array
    {
        return [
            'currentVersion'  => $this->currentVersion,
            'latestVersion'   => $this->latestVersion,
            'updateAvailable' => $this->updateAvailable,
            'checkEnabled'    => $this->checkEnabled,
            'lastCheckedAt'   => $this->lastCheckedAt,
            'lastError'       => $this->lastError,
            'updateCommand'   => $this->updateCommand,
        ];
    }
}
