<?php

/**
 * Prowlarr Client.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Arr;

use RuntimeException;

/**
 * Prowlarr API client for indexer management.
 *
 * @package Phlix\Shared\Arr
 * @since 0.4.0
 */
class ProwlarrClient extends AbstractArrClient
{
    /**
     * {@inheritdoc}
     */
    protected function vendorName(): string
    {
        return 'Prowlarr';
    }

    /**
     * Returns all configured indexers.
     *
     * @return array<int, array<string, mixed>> Indexers list.
     */
    public function getIndexers(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v1/indexer');
    }

    /**
     * Returns statistics for a specific indexer.
     *
     * @param int $indexerId The indexer ID.
     * @return array<string, mixed> Indexer stats.
     */
    public function getIndexerStats(int $indexerId): array
    {
        /** @var array<string, mixed> */
        return $this->get('/api/v1/indexer/' . $indexerId);
    }

    /**
     * Returns the health check results for Prowlarr.
     *
     * @return array<int, array<string, mixed>> Health issues.
     */
    public function getHealth(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v1/health');
    }

    /**
     * Triggers a reindex check for a specific indexer.
     *
     * @param int $indexerId The indexer ID to recheck.
     * @return bool True if the recheck was triggered successfully, false otherwise.
     */
    public function triggerReindexerCheck(int $indexerId): bool
    {
        try {
            $this->post('/api/v1/indexer/' . $indexerId . '/recheck', []);
            return true;
        } catch (RuntimeException $e) {
            $this->logger?->warning('Prowlarr trigger reindexer check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Tests connectivity and authentication with the Prowlarr instance.
     *
     * @return bool True if connection is successful, false otherwise.
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->get('/api/v1/system/status');
            return isset($response['version']);
        } catch (RuntimeException $e) {
            $this->logger?->warning(
                'Prowlarr connection test failed: '
                . SecretRedactor::redact($e->getMessage(), $this->apiKey)
            );
            return false;
        }
    }
}
