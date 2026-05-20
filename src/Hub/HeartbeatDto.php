<?php

declare(strict_types=1);

namespace Phlix\Shared\Hub;

use InvalidArgumentException;

/**
 * Server → Hub every ~60s once enrolled.
 *
 * Master plan §6 step 5.
 *
 * @package Phlix\Shared\Hub
 * @since 0.2.0
 */
final class HeartbeatDto
{
    /**
     * @param string         $serverId         Server UUID minted by the hub.
     * @param string         $version          Current server semver.
     * @param int            $timestamp        UNIX seconds at heartbeat send time.
     * @param int            $uptimeSeconds    How long the server process has been running.
     * @param int            $activeSessions   Concurrent playback session count.
     * @param int            $activeTranscodes Concurrent transcode count.
     * @param list<string>   $hostnameCandidates Reachable hostnames discovered since last heartbeat
     *                                           (UPnP/manual config).
     */
    public function __construct(
        public readonly string $serverId,
        public readonly string $version,
        public readonly int $timestamp,
        public readonly int $uptimeSeconds,
        public readonly int $activeSessions,
        public readonly int $activeTranscodes,
        public readonly array $hostnameCandidates,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException When a required field is missing or wrong-typed.
     */
    public static function fromPayload(array $payload): self
    {
        $serverId = self::requireString($payload, 'serverId');
        $version = self::requireString($payload, 'version');
        $timestamp = self::requireInt($payload, 'timestamp');
        $uptimeSeconds = self::requireInt($payload, 'uptimeSeconds');
        $activeSessions = self::requireInt($payload, 'activeSessions');
        $activeTranscodes = self::requireInt($payload, 'activeTranscodes');

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

        return new self(
            serverId: $serverId,
            version: $version,
            timestamp: $timestamp,
            uptimeSeconds: $uptimeSeconds,
            activeSessions: $activeSessions,
            activeTranscodes: $activeTranscodes,
            hostnameCandidates: $hostnameCandidates,
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
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requireString(array $payload, string $key): string
    {
        if (!array_key_exists($key, $payload)) {
            throw new InvalidArgumentException(sprintf('HeartbeatDto "%s" is required.', $key));
        }
        $value = $payload[$key];
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('HeartbeatDto "%s" must be a string.', $key));
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requireInt(array $payload, string $key): int
    {
        if (!array_key_exists($key, $payload)) {
            throw new InvalidArgumentException(sprintf('HeartbeatDto "%s" is required.', $key));
        }
        $value = $payload[$key];
        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('HeartbeatDto "%s" must be an integer.', $key));
        }
        return $value;
    }
}
