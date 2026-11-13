<?php

/**
 * Sonarr Client.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Arr;

use RuntimeException;

/**
 * Sonarr v3 API client for TV series management.
 *
 * ## Subclassing and the path to `final`
 *
 * Not `final` only because test doubles extend it
 * (`tests/Arr/SonarrClientTest.php::MockableSonarrClient`). The supported
 * seams are {@see ArrClientInterface} and the {@see Transport\ArrTransportInterface}
 * injection; outside those doubles treat this class as effectively final.
 * Flipping `final` is a BREAKING change to coordinate at a release seam once
 * the doubles move behind the interface or transport.
 *
 * @package Phlix\Shared\Arr
 * @since 0.4.0
 */
class SonarrClient extends AbstractArrClient implements ArrClientInterface
{
    /**
     * {@inheritdoc}
     */
    protected function vendorName(): string
    {
        return 'Sonarr';
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
     * Returns all tracked series.
     *
     * @return array<int, array<string, mixed>> Series list.
     */
    public function getSeries(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v3/series');
    }

    /**
     * Returns a specific series by its Sonarr ID.
     *
     * @param int $sonarrSeriesId The Sonarr series ID.
     * @return array<string, mixed> Series data.
     */
    public function getSeriesById(int $sonarrSeriesId): array
    {
        /** @var array<string, mixed> */
        return $this->get('/api/v3/series/' . $sonarrSeriesId);
    }

    /**
     * Returns a specific episode file by its ID.
     *
     * @param int $episodeId The episode file ID.
     * @return array<string, mixed> Episode file data.
     */
    public function getEpisodeFile(int $episodeId): array
    {
        /** @var array<string, mixed> */
        return $this->get('/api/v3/episodefile/' . $episodeId);
    }

    /**
     * Returns missing episodes (wanted).
     *
     * @param int|null $startSeason Optional season number to filter by.
     * @return array<int, array<string, mixed>> Missing episodes.
     */
    public function getWantedMissing(?int $startSeason = null): array
    {
        $path = '/api/v3/wanted/missing';
        if ($startSeason !== null) {
            $path .= '?season=' . $startSeason;
        }

        /** @var array<int, array<string, mixed>> */
        return $this->get($path);
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
     * {@inheritdoc}
     */
    public function getTagList(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v3/tag');
    }

    /**
     * Adds a new series to Sonarr.
     *
     * Sonarr's `POST /api/v3/series` adds exactly one series per call, keyed by
     * a single TVDB ID, and places it under a root folder named by its PATH
     * (e.g. `/tv`) — never an index. The signature says so: `int $tvdbId`
     * (the former `int|array` admitted a list the endpoint cannot take) and
     * `string $rootFolder` (the former `int` admitted only a meaningless
     * index). Changed in 0.x as a BREAKING fix while the seam is dormant.
     *
     * @param int $tvdbId The TVDB ID of the series to add.
     * @param int $qualityProfileId The quality profile ID to use.
     * @param string $rootFolder The root folder path (e.g. `/tv`).
     * @param string|null $monitor Monitoring option (default 'all'), sent as `addOptions.monitor`.
     * @param string|null $title Optional series title (some Sonarr builds require it on add).
     * @return array<string, mixed> Created series data.
     */
    public function addSeries(
        int $tvdbId,
        int $qualityProfileId,
        string $rootFolder,
        ?string $monitor = 'all',
        ?string $title = null
    ): array {
        $monitor = $monitor ?? 'all';
        // Real Sonarr v3 SeriesResource shape: `rootFolderPath` (not
        // `rootFolder`), top-level `monitored`, and the monitor strategy
        // nested under `addOptions.monitor`.
        $payload = [
            'tvdbId' => $tvdbId,
            'qualityProfileId' => $qualityProfileId,
            'rootFolderPath' => $rootFolder,
            'monitored' => $monitor !== 'none',
            'addOptions' => [
                'monitor' => $monitor,
                'searchForMissingEpisodes' => true,
            ],
        ];
        if ($title !== null && $title !== '') {
            $payload['title'] = $title;
        }

        /** @var array<string, mixed> */
        return $this->post('/api/v3/series', $payload);
    }

    /**
     * Triggers a download for an episode by marking it as wanted and forcing a search.
     *
     * @param int $episodeId The episode ID to trigger download for.
     * @return bool True if successful, false otherwise.
     */
    public function triggerDownload(int $episodeId): bool
    {
        try {
            $this->post('/api/v3/command', ['name' => 'EpisodeSearch', 'episodeIds' => [$episodeId]]);
            return true;
        } catch (RuntimeException $e) {
            $this->logger?->warning(
                'Sonarr trigger download failed: '
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
                'Sonarr connection test failed: '
                . SecretRedactor::redact($e->getMessage(), $this->apiKey)
            );
            return false;
        }
    }
}
