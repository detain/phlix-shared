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
final readonly class HeartbeatDto
{
    use PayloadAssert;

    /**
     * @param string         $serverId         Server UUID minted by the hub.
     * @param string         $version          Current server semver.
     * @param int            $timestamp        UNIX seconds at heartbeat send time.
     * @param int            $uptimeSeconds    How long the server process has been running.
     * @param int            $activeSessions   Concurrent playback session count.
     * @param int            $activeTranscodes Concurrent transcode count.
     * @param list<string>   $hostnameCandidates Reachable hosts discovered since last heartbeat
     *                                           (UPnP/manual config): bare host[:port] or
     *                                           absolute http(s) URL — grammar in {@see HostGrammar}.
     * @param list<LibraryRef> $libraries      Libraries on this server.
     */
    public function __construct(
        public string $serverId,
        public string $version,
        public int $timestamp,
        public int $uptimeSeconds,
        public int $activeSessions,
        public int $activeTranscodes,
        public array $hostnameCandidates,
        public array $libraries = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param bool                  $strictLibraries When true, throws on malformed library entries;
     *                                               when false (default), silently skips them (BC).
     *
     * Reject arms: `serverId`/`version` missing, non-string or empty;
     * integer counters missing or non-int; `hostnameCandidates` not a
     * list of strings or any entry outside the {@see HostGrammar} host/URL
     * grammar (one bad entry rejects the whole heartbeat — the DTO never
     * silently drops advertise material); `libraries` per `$strictLibraries`.
     *
     * @throws InvalidArgumentException When a required field is missing, wrong-typed, empty,
     *                                  or a hostname candidate is malformed.
     */
    public static function fromPayload(array $payload, bool $strictLibraries = false): self
    {
        $serverId = self::requireNonEmptyString($payload, 'serverId', 'HeartbeatDto');
        $version = self::requireNonEmptyString($payload, 'version', 'HeartbeatDto');
        $timestamp = self::requireInt($payload, 'timestamp', 'HeartbeatDto');
        $uptimeSeconds = self::requireInt($payload, 'uptimeSeconds', 'HeartbeatDto');
        $activeSessions = self::requireInt($payload, 'activeSessions', 'HeartbeatDto');
        $activeTranscodes = self::requireInt($payload, 'activeTranscodes', 'HeartbeatDto');

        $hostnameCandidates = HostGrammar::parseCandidates($payload, 'HeartbeatDto');

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
