<?php

/**
 * Radarr Client.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Arr;

use RuntimeException;

/**
 * Radarr v3 API client for movie management.
 *
 * @package Phlix\Shared\Arr
 * @since 0.4.0
 */
class RadarrClient extends AbstractArrClient implements ArrClientInterface
{
    /**
     * {@inheritdoc}
     */
    protected function vendorName(): string
    {
        return 'Radarr';
    }

    /**
     * {@inheritdoc}
     */
    public function getQueue(): array
    {
        /** @var array<string, mixed> $response Paginated response with keys: records, page, pageSize, totalRecords */
        $response = $this->get('/api/v3/queue');
        return $response;
    }

    /**
     * Returns all tracked movies.
     *
     * @return array<int, array<string, mixed>> Movies list.
     */
    public function getMovies(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v3/movie');
    }

    /**
     * Returns a specific movie by its Radarr ID.
     *
     * @param int $radarrId The Radarr movie ID.
     * @return array<string, mixed> Movie data.
     */
    public function getMovieById(int $radarrId): array
    {
        /** @var array<string, mixed> */
        return $this->get('/api/v3/movie/' . $radarrId);
    }

    /**
     * {@inheritdoc}
     */
    public function getQualityProfiles(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v3/qualityprofile');
    }

    /**
     * Returns all custom formats.
     *
     * @return array<int, array<string, mixed>> Custom formats.
     */
    public function getCustomFormats(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v3/customformat');
    }

    /**
     * Creates a new custom format in Radarr.
     *
     * @param array<string, mixed> $payload The custom format payload.
     * @return int The ID of the created custom format.
     */
    public function createCustomFormat(array $payload): int
    {
        $response = $this->post('/api/v3/customformat', $payload);
        /** @psalm-suppress MixedAssignment $response is array<mixed, mixed> from JSON */
        $id = $response['id'] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Updates an existing custom format in Radarr.
     *
     * @param int $id The custom format ID to update.
     * @param array<string, mixed> $payload The custom format payload.
     * @return bool True on success.
     */
    public function updateCustomFormat(int $id, array $payload): bool
    {
        $this->put('/api/v3/customformat/' . $id, $payload);
        return true;
    }

    /**
     * Deletes a custom format from Radarr.
     *
     * @param int $id The custom format ID to delete.
     * @return bool True on success.
     */
    public function deleteCustomFormat(int $id): bool
    {
        $this->delete('/api/v3/customformat/' . $id);
        return true;
    }

    /**
     * Creates a new quality profile in Radarr.
     *
     * @param array<string, mixed> $payload The quality profile payload.
     * @return int The ID of the created quality profile.
     */
    public function createQualityProfile(array $payload): int
    {
        $response = $this->post('/api/v3/qualityprofile', $payload);
        /** @psalm-suppress MixedAssignment $response is array<mixed, mixed> from JSON */
        $id = $response['id'] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Updates an existing quality profile in Radarr.
     *
     * @param int $id The quality profile ID to update.
     * @param array<string, mixed> $payload The quality profile payload.
     * @return bool True on success.
     */
    public function updateQualityProfile(int $id, array $payload): bool
    {
        $this->put('/api/v3/qualityprofile/' . $id, $payload);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getTagList(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v3/tag');
    }

    /**
     * Adds a new movie to Radarr.
     *
     * @param int|array<int> $tmdbId The TMDB ID or array of TMDB IDs.
     * @param int $qualityProfileId The quality profile ID to use.
     * @param string $rootFolder The root folder path.
     * @param bool $monitored Whether to monitor the movie (default true).
     * @return array<string, mixed> Created movie data.
     */
    public function addMovie(
        int|array $tmdbId,
        int $qualityProfileId,
        string $rootFolder,
        bool $monitored = true
    ): array {
        $payload = [
            'tmdbId' => $tmdbId,
            'qualityProfileId' => $qualityProfileId,
            'rootFolder' => $rootFolder,
            'monitored' => $monitored,
            'addOptions' => [
                'searchForMovie' => true,
            ],
        ];

        /** @var array<string, mixed> */
        return $this->post('/api/v3/movie', $payload);
    }

    /**
     * Triggers a download for a movie by marking it as wanted and forcing a search.
     *
     * @param int $movieId The movie ID to trigger download for.
     * @return bool True if successful, false otherwise.
     */
    public function triggerDownload(int $movieId): bool
    {
        try {
            $this->post('/api/v3/command', ['name' => 'MoviesSearch', 'movieIds' => [$movieId]]);
            return true;
        } catch (RuntimeException $e) {
            $this->logger?->warning(
                'Radarr trigger download failed: '
                . SecretRedactor::redact($e->getMessage(), $this->apiKey)
            );
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->get('/api/v3/system/status');
            return isset($response['version']);
        } catch (RuntimeException $e) {
            $this->logger?->warning(
                'Radarr connection test failed: '
                . SecretRedactor::redact($e->getMessage(), $this->apiKey)
            );
            return false;
        }
    }
}
