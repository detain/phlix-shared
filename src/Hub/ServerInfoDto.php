<?php

declare(strict_types=1);

namespace Phlix\Shared\Hub;

use InvalidArgumentException;
use Phlix\Shared\Support\PayloadAssert;

/**
 * Hub-side projection of an enrolled server, returned from
 * `GET /api/v1/users/{id}/servers` (Phase C.4 dashboard).
 *
 * @package Phlix\Shared\Hub
 * @since 0.2.0
 */
final class ServerInfoDto
{
    use PayloadAssert;

    public const STATUS_ONLINE = 'online';
    public const STATUS_OFFLINE = 'offline';
    public const STATUS_CLAIMING = 'claiming';
    public const STATUS_DISABLED = 'disabled';

    /**
     * @param string         $serverId           UUID minted by the hub on successful claim.
     * @param string         $userId             Owner UUID.
     * @param string         $serverName         From the original ClaimRequest.
     * @param string         $version            Server semver, refreshed on heartbeat.
     * @param int|null       $lastSeenAt         UNIX seconds. Null when never reached out.
     * @param string         $status             One of self::STATUS_*.
     * @param list<string>   $hostnameCandidates Last known reachable hostnames.
     * @param bool           $relayActive        Whether a WSS reverse tunnel is currently open (Phase C.6).
     * @param int|null       $libraryCount       Number of libraries the server last reported via heartbeat
     *                                            (from the hub's `server_libraries` cache). Null when the
     *                                            server has not reported any yet (older servers / pre-heartbeat).
     */
    public function __construct(
        public readonly string $serverId,
        public readonly string $userId,
        public readonly string $serverName,
        public readonly string $version,
        public readonly ?int $lastSeenAt,
        public readonly string $status,
        public readonly array $hostnameCandidates,
        public readonly bool $relayActive,
        public readonly ?int $libraryCount = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException When a required field is missing or wrong-typed.
     */
    public static function fromPayload(array $payload): self
    {
        $serverId = self::requireString($payload, 'serverId', 'ServerInfoDto');
        $userId = self::requireString($payload, 'userId', 'ServerInfoDto');
        $serverName = self::requireString($payload, 'serverName', 'ServerInfoDto');
        $version = self::requireString($payload, 'version', 'ServerInfoDto');
        $status = self::requireString($payload, 'status', 'ServerInfoDto');

        $lastSeenAt = null;
        if (array_key_exists('lastSeenAt', $payload) && $payload['lastSeenAt'] !== null) {
            if (!is_int($payload['lastSeenAt'])) {
                throw new InvalidArgumentException('ServerInfoDto "lastSeenAt" must be an integer when present.');
            }
            $lastSeenAt = $payload['lastSeenAt'];
        }

        $hostnameCandidates = [];
        if (array_key_exists('hostnameCandidates', $payload)) {
            if (!is_array($payload['hostnameCandidates'])) {
                throw new InvalidArgumentException('ServerInfoDto "hostnameCandidates" must be a list of strings.');
            }
            foreach ($payload['hostnameCandidates'] as $candidate) {
                if (!is_string($candidate)) {
                    throw new InvalidArgumentException('ServerInfoDto "hostnameCandidates" must contain only strings.');
                }
                $hostnameCandidates[] = $candidate;
            }
        }

        if (!array_key_exists('relayActive', $payload) || !is_bool($payload['relayActive'])) {
            throw new InvalidArgumentException('ServerInfoDto "relayActive" must be a boolean.');
        }

        $libraryCount = null;
        if (array_key_exists('libraryCount', $payload) && $payload['libraryCount'] !== null) {
            if (!is_int($payload['libraryCount'])) {
                throw new InvalidArgumentException('ServerInfoDto "libraryCount" must be an integer when present.');
            }
            $libraryCount = $payload['libraryCount'];
        }

        return new self(
            serverId: $serverId,
            userId: $userId,
            serverName: $serverName,
            version: $version,
            lastSeenAt: $lastSeenAt,
            status: $status,
            hostnameCandidates: $hostnameCandidates,
            relayActive: $payload['relayActive'],
            libraryCount: $libraryCount,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'serverId' => $this->serverId,
            'userId' => $this->userId,
            'serverName' => $this->serverName,
            'version' => $this->version,
            'lastSeenAt' => $this->lastSeenAt,
            'status' => $this->status,
            'hostnameCandidates' => $this->hostnameCandidates,
            'relayActive' => $this->relayActive,
            'libraryCount' => $this->libraryCount,
        ];
    }
}
