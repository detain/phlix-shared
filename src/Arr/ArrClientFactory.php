<?php

/**
 * Arr Client Factory.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Arr;

use InvalidArgumentException;
use Phlix\Shared\Arr\Transport\ArrTransportInterface;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating Sonarr/Radarr API clients from config.
 *
 * An optional {@see ArrTransportInterface} may be supplied; when present it is
 * propagated to every created client so event-loop consumers can wire a single
 * async, non-blocking transport once. When omitted, clients fall back to the
 * bundled blocking {@see \Phlix\Shared\Arr\Transport\CurlArrTransport}.
 *
 * POSTURE TRUTH (phlix-shared-021): an earlier revision of this note filed the
 * default transport as a CLI-only / test-rig-only vehicle, a label the boot
 * reality never matched — phlix-hub's DI builds this factory with no transport, so
 * hub workers run precisely that blocking default today. It IS the right shape
 * for CLI scripts and tests, and elsewhere it is a tolerated worker STALL
 * rather than a prohibited construction: see the RUNTIME TRUTH note on
 * {@see AbstractArrClient} for why the hazard is a stall (up to the request
 * timeout, per call) and not a coroutine property, and why cooperative
 * event-loop consumers — Workerman/Webman hosts and the hub alike — should
 * inject an async, non-blocking transport here instead, which this factory
 * then propagates to every client it builds.
 *
 * Each config section may additionally carry a positive-integer
 * `connect_timeout` (seconds, the digit-string form env readers produce, or
 * null-as-absent); an enabled section that sets it forwards the value to the
 * created client's connect-phase knob (the {@see AbstractArrClient}
 * constructor argument from phlix-shared-032 — the factory-forwarding leg was
 * disclosed there as deferred to this step). Like the overall request timeout
 * it reaches the default transport only: injected transports own their own
 * timing.
 *
 * A create*() method returns null for BOTH an operator-disabled section AND an
 * enabled-but-keyless one; callers that must tell the two apart use
 * {@see self::misconfigured()} instead of log-scraping the warning.
 *
 * @package Phlix\Shared\Arr
 * @since 0.4.0
 */
final class ArrClientFactory
{
    /**
     * @param array{
     *     sonarr?: array{url?: string, api_key?: string, enabled?: bool, connect_timeout?: int|string|null},
     *     radarr?: array{url?: string, api_key?: string, enabled?: bool, connect_timeout?: int|string|null}
     * } $config Configuration array with sonarr/radarr sections. Each section
     *     may set `connect_timeout` (positive int seconds, the digit-string
     *     form env reads produce, or null for absent) to bound the TCP+TLS
     *     handshake phase of clients built on the default transport.
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
     * @return SonarrClient|null Client instance, or null if Sonarr is disabled
     *     OR enabled with an empty api_key — the enabled-but-keyless branch is
     *     a misconfiguration, distinguishable via {@see self::misconfigured()}.
     * @throws InvalidArgumentException When the enabled section carries a
     *     `connect_timeout` that is neither absent nor a positive int.
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

        $connectTimeout = self::resolveConnectTimeout($sonarrConfig, 'sonarr');

        return $connectTimeout === null
            ? new SonarrClient($url, $apiKey, $logger, 30, $this->transport)
            : new SonarrClient($url, $apiKey, $logger, 30, $this->transport, $connectTimeout);
    }

    /**
     * Creates a RadarrClient from the config.
     *
     * @param LoggerInterface|null $logger Optional logger instance.
     * @return RadarrClient|null Client instance, or null if Radarr is disabled
     *     OR enabled with an empty api_key — the enabled-but-keyless branch is
     *     a misconfiguration, distinguishable via {@see self::misconfigured()}.
     * @throws InvalidArgumentException When the enabled section carries a
     *     `connect_timeout` that is neither absent nor a positive int.
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

        $connectTimeout = self::resolveConnectTimeout($radarrConfig, 'radarr');

        return $connectTimeout === null
            ? new RadarrClient($url, $apiKey, $logger, 30, $this->transport)
            : new RadarrClient($url, $apiKey, $logger, 30, $this->transport, $connectTimeout);
    }

    /**
     * Names the provider sections that are enabled but cannot yield a client
     * because their api_key is absent or empty.
     *
     * This is the additive detector for the conflation phlix-shared-021
     * reported: create*() answers operator-disabled and misconfigured-on with
     * the same null, and until now the only way to tell them apart was
     * log-scraping the warning the keyless branch emits. The branch law is
     * shared with the creators through {@see self::isEnabledWithoutKey()}, so
     * the detector can never drift from what create*() actually does; it is
     * pure config inspection — no client is built and no I/O happens.
     *
     * @return list<'radarr'|'sonarr'> Misconfigured section names in the
     *     fixed order sonarr, radarr; empty when every enabled section is
     *     usable.
     */
    public function misconfigured(): array
    {
        $misconfigured = [];

        $sections = [
            'sonarr' => $this->config['sonarr'] ?? [],
            'radarr' => $this->config['radarr'] ?? [],
        ];
        foreach ($sections as $provider => $section) {
            if (self::isEnabledWithoutKey($section)) {
                $misconfigured[] = $provider;
            }
        }

        return $misconfigured;
    }

    /**
     * The single branch law shared by the create*() keyless return and
     * {@see self::misconfigured()}: a truthy `enabled` (exactly the creators'
     * truthiness test) plus an absent-or-empty `api_key`.
     *
     * @param array<string, mixed> $section One provider's config section.
     */
    private static function isEnabledWithoutKey(array $section): bool
    {
        return (bool) (($section['enabled'] ?? false) && ($section['api_key'] ?? '') === '');
    }

    /**
     * Parses a section's optional `connect_timeout` into the client
     * constructor's connect-phase argument.
     *
     * Absent (or explicitly null) yields null so the client's own default
     * stands — the factory never re-declares a default the client owns.
     * Digit strings are admitted because env-backed config layers hand them
     * over verbatim (the hub's `server.php` arr section is exactly such a
     * layer); anything else that is present must already be a positive int.
     *
     * @param array<string, mixed> $section  One provider's config section.
     * @param string               $provider Section name, for error wording.
     * @return int|null Positive connect-phase seconds, or null when unset.
     * @throws InvalidArgumentException When present but not a positive int
     *     (or positive digit string).
     */
    private static function resolveConnectTimeout(array $section, string $provider): ?int
    {
        if (!isset($section['connect_timeout'])) {
            return null;
        }

        $raw = $section['connect_timeout'];
        $seconds = (is_int($raw) || (is_string($raw) && ctype_digit($raw))) ? (int) $raw : 0;

        if ($seconds < 1) {
            throw new InvalidArgumentException(sprintf(
                'ArrClientFactory: %s connect_timeout must be a positive integer of seconds; got %s.',
                $provider,
                is_int($raw) || is_string($raw) ? var_export($raw, true) : get_debug_type($raw)
            ));
        }

        return $seconds;
    }
}
