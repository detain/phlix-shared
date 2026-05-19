<?php

declare(strict_types=1);

namespace Phlex\Shared\Arr;

/**
 * Common interface for Sonarr/Radarr API clients.
 *
 * @package Phlex\Shared\Arr
 * @since 0.12.0
 */
interface ArrClientInterface
{
    /**
     * Returns the current download/activity queue.
     *
     * The Sonarr/Radarr v3 queue endpoint returns a paginated response of the
     * shape `{records, page, pageSize, sortKey, sortDirection, totalRecords}`,
     * so we expose this as `array<string, mixed>` rather than a list.
     *
     * @return array<string, mixed> Paginated queue response.
     */
    public function getQueue(): array;

    /**
     * Returns available quality profiles.
     *
     * @return array<int, array<string, mixed>> Quality profiles.
     */
    public function getQualityProfiles(): array;

    /**
     * Returns all configured tags.
     *
     * @return array<int, array<string, mixed>> Tags.
     */
    public function getTagList(): array;

    /**
     * Tests connectivity and authentication with the *arr instance.
     *
     * @return bool True if connection is successful, false otherwise.
     */
    public function testConnection(): bool;
}
