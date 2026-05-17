<?php

declare(strict_types=1);

namespace Phlex\Shared\Auth;

use InvalidArgumentException;

/**
 * Immutable claim shape for Phlex JWTs (access and refresh).
 *
 * Captures the payload `Phlex\Auth\JwtHandler::createAccessToken()` and
 * `createRefreshToken()` produce today, plus the additional `aud` and
 * `scope` fields the hub will emit starting in Phase C.5.
 *
 * Phase C.5 wires `JwtHandler::validateToken()` to deserialize the
 * decoded payload into this DTO so server and hub share one definition
 * of "what's in a Phlex JWT".
 *
 * @package Phlex\Shared\Auth
 * @since 0.2.0
 */
final class JwtClaims
{
    public const ISS_PHLEX = 'phlex';
    public const ISS_PHLEX_HUB = 'phlex-hub';
    public const AUD_SERVER = 'server';
    public const AUD_HUB    = 'hub';
    public const AUD_CLIENT = 'client';
    public const TYPE_ACCESS  = 'access';
    public const TYPE_REFRESH = 'refresh';

    /**
     * @param string         $iss   Issuer. `phlex` for server-minted, `phlex-hub` for hub-minted.
     * @param string         $aud   Audience. One of self::AUD_*.
     * @param string         $sub   Subject — user UUID.
     * @param int            $iat   Issued-at, UNIX seconds.
     * @param int            $exp   Expires-at, UNIX seconds.
     * @param int|null       $nbf   Not-before, UNIX seconds. Null when unset.
     * @param string         $type  Token kind. One of self::TYPE_*.
     * @param string|null    $jti   Refresh-only token identifier. Null on access tokens.
     * @param list<string>   $scope Permissions list (e.g. `["library:read","playback:write"]`). Empty when unscoped.
     * @param string|null    $serverId Optional server UUID for hub-minted client tokens. Null on server-minted.
     */
    public function __construct(
        public readonly string $iss,
        public readonly string $aud,
        public readonly string $sub,
        public readonly int $iat,
        public readonly int $exp,
        public readonly ?int $nbf,
        public readonly string $type,
        public readonly ?string $jti,
        public readonly array $scope,
        public readonly ?string $serverId,
    ) {
    }

    /**
     * Build from the array shape `JwtHandler::validateToken()` returns today.
     *
     * Tolerant of missing optional fields (`nbf`, `jti`, `scope`,
     * `serverId`). Throws {@see InvalidArgumentException} when any of
     * the RFC 7519 required fields is missing or has the wrong type.
     *
     * @param array<string, mixed> $payload Decoded token payload.
     *
     * @throws InvalidArgumentException When a required field is missing or wrong-typed.
     */
    public static function fromPayload(array $payload): self
    {
        $iss = self::requireString($payload, 'iss');
        $sub = self::requireString($payload, 'sub');
        $iat = self::requireInt($payload, 'iat');
        $exp = self::requireInt($payload, 'exp');
        $type = self::requireString($payload, 'type');

        // `aud` is required per the shared shape; default to AUD_SERVER
        // for tokens emitted by the legacy `JwtHandler` that pre-date
        // the field. This keeps `fromPayload()` tolerant of v0.10.x
        // payloads but still surfaces wrong-typed values.
        $aud = self::AUD_SERVER;
        if (array_key_exists('aud', $payload)) {
            if (!is_string($payload['aud'])) {
                throw new InvalidArgumentException('JWT claim "aud" must be a string.');
            }
            $aud = $payload['aud'];
        }

        $nbf = null;
        if (array_key_exists('nbf', $payload) && $payload['nbf'] !== null) {
            if (!is_int($payload['nbf'])) {
                throw new InvalidArgumentException('JWT claim "nbf" must be an integer when present.');
            }
            $nbf = $payload['nbf'];
        }

        $jti = null;
        if (array_key_exists('jti', $payload) && $payload['jti'] !== null) {
            if (!is_string($payload['jti'])) {
                throw new InvalidArgumentException('JWT claim "jti" must be a string when present.');
            }
            $jti = $payload['jti'];
        }

        $scope = [];
        if (array_key_exists('scope', $payload) && $payload['scope'] !== null) {
            if (!is_array($payload['scope'])) {
                throw new InvalidArgumentException('JWT claim "scope" must be a list of strings when present.');
            }
            foreach ($payload['scope'] as $entry) {
                if (!is_string($entry)) {
                    throw new InvalidArgumentException('JWT claim "scope" must contain only strings.');
                }
                $scope[] = $entry;
            }
        }

        $serverId = null;
        if (array_key_exists('serverId', $payload) && $payload['serverId'] !== null) {
            if (!is_string($payload['serverId'])) {
                throw new InvalidArgumentException('JWT claim "serverId" must be a string when present.');
            }
            $serverId = $payload['serverId'];
        }

        return new self(
            iss: $iss,
            aud: $aud,
            sub: $sub,
            iat: $iat,
            exp: $exp,
            nbf: $nbf,
            type: $type,
            jti: $jti,
            scope: $scope,
            serverId: $serverId,
        );
    }

    /**
     * Serialize to the array shape `JwtHandler::encode()` expects today.
     *
     * Optional fields are omitted when null/empty so v0.10.x decoders
     * that pre-date them don't see unexpected keys.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'iss' => $this->iss,
            'aud' => $this->aud,
            'sub' => $this->sub,
            'iat' => $this->iat,
            'exp' => $this->exp,
            'type' => $this->type,
        ];

        if ($this->nbf !== null) {
            $payload['nbf'] = $this->nbf;
        }
        if ($this->jti !== null) {
            $payload['jti'] = $this->jti;
        }
        if ($this->scope !== []) {
            $payload['scope'] = $this->scope;
        }
        if ($this->serverId !== null) {
            $payload['serverId'] = $this->serverId;
        }

        return $payload;
    }

    /**
     * True when the token's `exp` claim is in the past relative to
     * `$now` (or `time()` when omitted).
     */
    public function isExpired(?int $now = null): bool
    {
        $now ??= time();
        return $this->exp < $now;
    }

    /**
     * True when `$scope` is present in this token's scope list.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scope, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requireString(array $payload, string $key): string
    {
        if (!array_key_exists($key, $payload)) {
            throw new InvalidArgumentException(sprintf('JWT claim "%s" is required.', $key));
        }
        $value = $payload[$key];
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('JWT claim "%s" must be a string.', $key));
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requireInt(array $payload, string $key): int
    {
        if (!array_key_exists($key, $payload)) {
            throw new InvalidArgumentException(sprintf('JWT claim "%s" is required.', $key));
        }
        $value = $payload[$key];
        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('JWT claim "%s" must be an integer.', $key));
        }
        return $value;
    }
}
