<?php

/**
 * Heartbeat Dto.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Hub;

use InvalidArgumentException;
use Phlix\Shared\Support\PayloadAssert;

/**
 * Server → Hub every ~60s once enrolled.
 *
 * @package Phlix\Shared\Hub
 * @since 0.2.0
 */
final class HeartbeatDto
{
    use PayloadAssert;

    /**
     * @param string         $serverId         Server UUID minted by the hub.
     * @param string         $version          Current server semver.
     * @param int            $timestamp        UNIX seconds at heartbeat send time.
     * @param int            $uptimeSeconds    How long the server process has been running.
     * @param int            $activeSessions   Concurrent playback session count.
     * @param int            $activeTranscodes Concurrent transcode count.
     * @param list<string>   $hostnameCandidates Reachable hostnames discovered since last heartbeat
     *                                           (UPnP/manual config).
     * @param list<LibraryRef> $libraries      Libraries on this server.
     */
    public function __construct(
        public readonly string $serverId,
        public readonly string $version,
        public readonly int $timestamp,
        public readonly int $uptimeSeconds,
        public readonly int $activeSessions,
        public readonly int $activeTranscodes,
        public readonly array $hostnameCandidates,
        public readonly array $libraries = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param bool                  $strictLibraries When true, throws on malformed library entries;
     *                                               when false (default), silently skips them (BC).
     *
     * @throws InvalidArgumentException When a required field is missing or wrong-typed.
     */
    public static function fromPayload(array $payload, bool $strictLibraries = false): self
    {
        $serverId = self::requireString($payload, 'serverId', 'HeartbeatDto');
        $version = self::requireString($payload, 'version', 'HeartbeatDto');
        $timestamp = self::requireInt($payload, 'timestamp', 'HeartbeatDto');
        $uptimeSeconds = self::requireInt($payload, 'uptimeSeconds', 'HeartbeatDto');
        $activeSessions = self::requireInt($payload, 'activeSessions', 'HeartbeatDto');
        $activeTranscodes = self::requireInt($payload, 'activeTranscodes', 'HeartbeatDto');

        $hostnameCandidates = [];
        if (array_key_exists('hostnameCandidates', $payload)) {
            if (!is_array($payload['hostnameCandidates'])) {
                throw new InvalidArgumentException('HeartbeatDto "hostnameCandidates" must be a list of strings.');
            }
            foreach ($payload['hostnameCandidates'] as $candidate) {
                if (!is_string($candidate)) {
                    throw new InvalidArgumentException('HeartbeatDto "hostnameCandidates" must contain only strings.');
                }
                $hostnameCandidates[] = $candidate;
            }
        }

        $libraries = [];
        if (array_key_exists('libraries', $payload)) {
            if (!is_array($payload['libraries'])) {
                throw new InvalidArgumentException('HeartbeatDto "libraries" must be a list of objects.');
            }
            foreach ($payload['libraries'] as $lib) {
                if (!is_array($lib)) {
                    if ($strictLibraries) {
                        throw new InvalidArgumentException('HeartbeatDto "libraries" must contain objects.');
                    }
                    continue;
                }
                if ($strictLibraries) {
                    /** @var array<string, mixed> $lib */
                    $libraries[] = LibraryRef::fromPayload($lib);
                } else {
                    /** @var string $libId */
                    $libId = is_string($lib['library_id'] ?? null) ? $lib['library_id'] : '';
                    /** @var string $libName */
                    $libName = is_string($lib['library_name'] ?? null) ? $lib['library_name'] : '';
                    if ($libId !== '' && $libName !== '') {
                        $libraries[] = new LibraryRef($libId, $libName);
                    }
                }
            }
        }

        return new self(
            serverId: $serverId,
            version: $version,
            timestamp: $timestamp,
            uptimeSeconds: $uptimeSeconds,
            activeSessions: $activeSessions,
            activeTranscodes: $activeTranscodes,
            hostnameCandidates: $hostnameCandidates,
            libraries: $libraries,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'serverId' => $this->serverId,
            'version' => $this->version,
            'timestamp' => $this->timestamp,
            'uptimeSeconds' => $this->uptimeSeconds,
            'activeSessions' => $this->activeSessions,
            'activeTranscodes' => $this->activeTranscodes,
            'hostnameCandidates' => $this->hostnameCandidates,
            'libraries' => array_map(
                fn(LibraryRef $ref) => $ref->toPayload(),
                $this->libraries,
            ),
        ];
    }
}
