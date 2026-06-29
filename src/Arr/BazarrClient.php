<?php

declare(strict_types=1);

namespace Phlix\Shared\Arr;

use RuntimeException;

/**
 * Bazarr API client for subtitle management.
 *
 * @package Phlix\Shared\Arr
 * @since 0.4.0
 */
class BazarrClient extends AbstractArrClient
{
    /**
     * {@inheritdoc}
     */
    protected function vendorName(): string
    {
        return 'Bazarr';
    }

    /**
     * Returns subtitles for a Sonarr series (and optionally a specific episode).
     *
     * @param string $sonarrSeriesId The Sonarr series ID.
     * @param int|null $episodeFileId Optional episode file ID to filter by.
     * @return array<int, array<string, mixed>> Subtitles list.
     */
    public function getSubtitles(string $sonarrSeriesId, ?int $episodeFileId = null): array
    {
        $path = '/api/v1/subtitles?sonarrSeriesId=' . urlencode($sonarrSeriesId);
        if ($episodeFileId !== null) {
            $path .= '&episodeFileId=' . $episodeFileId;
        }

        /** @var array<int, array<string, mixed>> */
        return $this->get($path);
    }

    /**
     * Returns available subtitle languages for a specific video file.
     *
     * @param string $videoFilePath The full path to the video file.
     * @return array<int, array<string, mixed>> Languages list.
     */
    public function getSubtitleLanguages(string $videoFilePath): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v1/languages?path=' . urlencode($videoFilePath));
    }

    /**
     * Downloads a subtitle for a specific video file and language.
     *
     * @param string $videoFilePath The full path to the video file.
     * @param string $languageCode The language code for the subtitle (e.g. `en`, `es`, `pt-BR`).
     * @return array<string, mixed> Download result.
     */
    public function downloadSubtitle(string $videoFilePath, string $languageCode): array
    {
        $payload = [
            'path' => $videoFilePath,
            'language' => $languageCode,
        ];

        /** @var array<string, mixed> */
        return $this->post('/api/v1/subtitles/download', $payload);
    }

    /**
     * Returns all available subtitle languages configured in Bazarr.
     *
     * @return array<int, array<string, mixed>> Languages list.
     */
    public function getLanguages(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v1/languages/list');
    }

    /**
     * Tests connectivity and authentication with the Bazarr instance.
     *
     * @return bool True if connection is successful, false otherwise.
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->get('/api/v1/system');
            return isset($response['version']) || isset($response['bazarr']);
        } catch (RuntimeException $e) {
            $this->logger?->warning(
                'Bazarr connection test failed: '
                . SecretRedactor::redact($e->getMessage(), $this->apiKey)
            );
            return false;
        }
    }
}
