<?php

/**
 * Claim Request.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Hub;

use InvalidArgumentException;
use Phlix\Shared\Support\PayloadAssert;

/**
 * Server → Hub claim-flow start payload.
 *
 * Master plan §6 step 2. Shipped in v0.2.0 with the locked field shape;
 * actually wired in Phase C.1 (hub registry) / C.2 (server's HubClient).
 *
 * KEY CONTRACT (delegated): `publicKeysJwk` is gated here as a JSON object
 * (PHP array) only. Key type, curve and key material are NOT validated by
 * this DTO — a `kty` allowlist here would be a second, looser policy that
 * drifts from the one that matters. The consumer that verifies with the key
 * owns that policy and MUST enforce it before persisting or trusting the
 * key; today that is phlix-hub `ClaimRequestHandler::validateJwkStructure()`
 * (single JWK, `kty` OKP + `crv` Ed25519 + 32-byte `x`, matching the
 * server's `Ed25519KeyManager::getPublicKeyJwk()`). A new consumer of
 * `fromPayload()` inherits that obligation.
 *
 * @package Phlix\Shared\Hub
 * @since 0.2.0
 */
final readonly class ClaimRequest
{
    use PayloadAssert;

    /**
     * @param string              $serverName         Operator-chosen friendly name (e.g. "Alice's NAS").
     * @param string              $version            Server semver.
     * @param array<string,mixed> $publicKeysJwk      Public JWK the server publishes for hub-minted token
     *                                                validation (key policy delegated — see class docblock).
     * @param list<string>        $hostnameCandidates Hosts/IPs/http(s) URLs the server thinks it's reachable
     *                                                at (for relay-or-direct decisions) — grammar in
     *                                                {@see HostGrammar}.
     * @param string              $protocolVersion    Spec version — start at "v1"; check via
     *                                                Accept-Phlix-Protocol header.
     */
    public function __construct(
        public string $serverName,
        public string $version,
        public array $publicKeysJwk,
        public array $hostnameCandidates,
        public string $protocolVersion,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * Reject arms: `serverName`/`version`/`protocolVersion` missing,
     * non-string or empty; `publicKeysJwk` missing or not an object (its
     * key policy is delegated — class docblock); `hostnameCandidates` not a
     * list of strings or any entry outside the {@see HostGrammar} grammar.
     *
     * @throws InvalidArgumentException When a required field is missing, wrong-typed, empty,
     *                                  or a hostname candidate is malformed.
     */
    public static function fromPayload(array $payload): self
    {
        $serverName = self::requireNonEmptyString($payload, 'serverName', 'ClaimRequest');
        $version = self::requireNonEmptyString($payload, 'version', 'ClaimRequest');
        $protocolVersion = self::requireNonEmptyString($payload, 'protocolVersion', 'ClaimRequest');

        if (!array_key_exists('publicKeysJwk', $payload) || !is_array($payload['publicKeysJwk'])) {
            throw new InvalidArgumentException('ClaimRequest "publicKeysJwk" must be a JWKS array.');
        }
        /** @var array<string,mixed> $publicKeysJwk */
        $publicKeysJwk = $payload['publicKeysJwk'];

        $hostnameCandidates = HostGrammar::parseCandidates($payload, 'ClaimRequest');

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
}
