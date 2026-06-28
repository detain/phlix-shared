<?php

declare(strict_types=1);

namespace Phlix\Shared\Arr;

use Phlix\Shared\Arr\Transport\ArrTransportInterface;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating Sonarr/Radarr API clients from config.
 *
 * An optional {@see ArrTransportInterface} may be supplied; when present it is
 * propagated to every created client so event-loop consumers can wire a single
 * async, non-blocking transport once. When omitted, clients fall back to the
 * bundled blocking {@see \Phlix\Shared\Arr\Transport\CurlArrTransport} (CLI/test only).
 *
 * @package Phlix\Shared\Arr
 * @since 0.4.0
 */
class ArrClientFactory
{
    /**
     * @param array{
     *     sonarr?: array{url?: string, api_key?: string, enabled?: bool},
     *     radarr?: array{url?: string, api_key?: string, enabled?: bool}
     * } $config Configuration array with sonarr/radarr sections.
     * @param ArrTransportInterface|null $transport Optional HTTP transport propagated to
     *     every created client. When null, clients use the default blocking cURL transport.
     */
    public function __construct(
        private readonly array $config,
        private readonly ?ArrTransportInterface $transport = null
    ) {
    }

    /**
     * Creates a SonarrClient from the config.
     *
     * @param LoggerInterface|null $logger Optional logger instance.
     * @return SonarrClient|null Client instance, or null if Sonarr is not enabled.
     */
    public function createSonarrClient(?LoggerInterface $logger = null): ?SonarrClient
    {
        $sonarrConfig = $this->config['sonarr'] ?? [];

        if (!($sonarrConfig['enabled'] ?? false)) {
            return null;
        }

        $url = $sonarrConfig['url'] ?? 'http://localhost:8989';
        $apiKey = $sonarrConfig['api_key'] ?? '';

        if ($apiKey === '') {
            $logger?->warning('Sonarr API key is empty but client is enabled');
            return null;
        }

        return new SonarrClient($url, $apiKey, $logger, 30, $this->transport);
    }

    /**
     * Creates a RadarrClient from the config.
     *
     * @param LoggerInterface|null $logger Optional logger instance.
     * @return RadarrClient|null Client instance, or null if Radarr is not enabled.
     */
    public function createRadarrClient(?LoggerInterface $logger = null): ?RadarrClient
    {
        $radarrConfig = $this->config['radarr'] ?? [];

        if (!($radarrConfig['enabled'] ?? false)) {
            return null;
        }

        $url = $radarrConfig['url'] ?? 'http://localhost:7878';
        $apiKey = $radarrConfig['api_key'] ?? '';

        if ($apiKey === '') {
            $logger?->warning('Radarr API key is empty but client is enabled');
            return null;
        }

        return new RadarrClient($url, $apiKey, $logger, 30, $this->transport);
    }
}
