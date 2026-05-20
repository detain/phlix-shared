<?php

declare(strict_types=1);

namespace Phlix\Shared\Hub;

use InvalidArgumentException;

/**
 * Server → Hub claim-flow start payload.
 *
 * Master plan §6 step 2. Shipped in v0.2.0 with the locked field shape;
 * actually wired in Phase C.1 (hub registry) / C.2 (server's HubClient).
 *
 * @package Phlix\Shared\Hub
 * @since 0.2.0
 */
final class ClaimRequest
{
    /**
     * @param string              $serverName         Operator-chosen friendly name (e.g. "Alice's NAS").
     * @param string              $version            Server semver.
     * @param array<string,mixed> $publicKeysJwk      JWKS the server publishes for hub-minted token
     *                                                validation.
     * @param list<string>        $hostnameCandidates Hostnames/IPs the server thinks it's reachable at
     *                                                (for relay-or-direct decisions).
     * @param string              $protocolVersion    Spec version — start at "v1"; check via
     *                                                Accept-Phlix-Protocol header.
     */
    public function __construct(
        public readonly string $serverName,
        public readonly string $version,
        public readonly array $publicKeysJwk,
        public readonly array $hostnameCandidates,
        public readonly string $protocolVersion,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException When a required field is missing or wrong-typed.
     */
    public static function fromPayload(array $payload): self
    {
        $serverName = self::requireString($payload, 'serverName');
        $version = self::requireString($payload, 'version');
        $protocolVersion = self::requireString($payload, 'protocolVersion');

        if (!array_key_exists('publicKeysJwk', $payload) || !is_array($payload['publicKeysJwk'])) {
            throw new InvalidArgumentException('ClaimRequest "publicKeysJwk" must be a JWKS array.');
        }
        /** @var array<string,mixed> $publicKeysJwk */
        $publicKeysJwk = $payload['publicKeysJwk'];

        $hostnameCandidates = [];
        if (array_key_exists('hostnameCandidates', $payload)) {
            if (!is_array($payload['hostnameCandidates'])) {
                throw new InvalidArgumentException('ClaimRequest "hostnameCandidates" must be a list of strings.');
            }
            foreach ($payload['hostnameCandidates'] as $candidate) {
                if (!is_string($candidate)) {
                    throw new InvalidArgumentException('ClaimRequest "hostnameCandidates" must contain only strings.');
                }
                $hostnameCandidates[] = $candidate;
            }
        }

        return new self(
            serverName: $serverName,
            version: $version,
            publicKeysJwk: $publicKeysJwk,
            hostnameCandidates: $hostnameCandidates,
            protocolVersion: $protocolVersion,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'serverName' => $this->serverName,
            'version' => $this->version,
            'publicKeysJwk' => $this->publicKeysJwk,
            'hostnameCandidates' => $this->hostnameCandidates,
            'protocolVersion' => $this->protocolVersion,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requireString(array $payload, string $key): string
    {
        if (!array_key_exists($key, $payload)) {
            throw new InvalidArgumentException(sprintf('ClaimRequest "%s" is required.', $key));
        }
        $value = $payload[$key];
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('ClaimRequest "%s" must be a string.', $key));
        }
        return $value;
    }
}
