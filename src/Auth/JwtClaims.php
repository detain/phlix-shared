<?php

declare(strict_types=1);

namespace Phlix\Shared\Auth;

use InvalidArgumentException;
use Phlix\Shared\Support\PayloadAssert;

/**
 * Immutable claim shape for Phlix JWTs (access and refresh).
 *
 * Captures the payload `Phlix\Auth\JwtHandler::createAccessToken()` and
 * `createRefreshToken()` produce today, plus the additional `aud` and
 * `scope` fields the hub will emit starting in Phase C.5.
 *
 * Phase C.5 wires `JwtHandler::validateToken()` to deserialize the
 * decoded payload into this DTO so server and hub share one definition
 * of "what's in a Phlix JWT".
 *
 * ## SECURITY: this class performs NO signature verification.
 *
 * `JwtClaims` is purely a typed *view* over an array payload that has
 * **already been decoded and cryptographically verified** by the caller.
 * Neither {@see self::fromPayload()} nor {@see self::fromPayloadStrict()}
 * checks the JWT signature, the `alg` header, key identity, or any HMAC —
 * they only validate field presence and PHP types. Verifying the token
 * signature (and rejecting `alg: none`) is the **caller's responsibility**:
 * server and hub do this in their respective `JwtHandler` before passing
 * the decoded payload here. Constructing a `JwtClaims` from an unverified
 * payload conveys **no** authenticity guarantee.
 *
 * ## Audience (`aud`) handling
 *
 * {@see self::fromPayload()} defaults a missing `aud` to {@see self::AUD_SERVER}
 * as a deliberate v0.10.x backward-compat shim for legacy tokens minted by
 * `JwtHandler` before the field existed. Once every issuer emits `aud`,
 * consumers should migrate to {@see self::fromPayloadStrict()}, which throws
 * on a missing `aud` instead of defaulting.
 *
 * ## Round-trip / `toPayload()` asymmetry (deliberate)
 *
 * {@see self::toPayload()} intentionally **omits** null/empty optional claims
 * (`nbf`, `jti`, `scope`, `serverId`) from its output so that legacy decoders
 * predating those fields never see unexpected keys — this is a wire-compat
 * requirement and must NOT change. The serialization is therefore
 * asymmetric at the array level (a minimal claim set produces fewer keys than
 * the full constructor), yet `fromPayload(toPayload($claims)) == $claims`
 * still holds: `fromPayload()` re-applies the same null/empty defaults for the
 * omitted keys, so the object round-trip is lossless. See the round-trip tests
 * in `JwtClaimsTest` for both the all-fields and minimal-claims cases.
 *
 * @package Phlix\Shared\Auth
 * @since 0.2.0
 */
final class JwtClaims
{
    use PayloadAssert;

    public const ISS_PHLIX = 'phlix';
    public const ISS_PHLIX_HUB = 'phlix-hub';
    public const AUD_SERVER = 'server';
    public const AUD_HUB    = 'hub';
    public const AUD_CLIENT = 'client';
    public const TYPE_ACCESS  = 'access';
    public const TYPE_REFRESH = 'refresh';

    /**
     * @param string         $iss   Issuer. `phlix` for server-minted, `phlix-hub` for hub-minted.
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
     * `serverId`) and of a missing `aud` (defaulted to {@see self::AUD_SERVER}
     * for legacy tokens — see the class docblock). Throws
     * {@see InvalidArgumentException} when any of the RFC 7519 required fields
     * is missing or has the wrong type.
     *
     * Performs **no** signature verification — see the class docblock.
     *
     * @param array<string, mixed> $payload Decoded token payload.
     *
     * @throws InvalidArgumentException When a required field is missing or wrong-typed.
     */
    public static function fromPayload(array $payload): self
    {
        // `aud` is required per the shared shape; default to AUD_SERVER
        // for tokens emitted by the legacy `JwtHandler` that pre-date
        // the field. This keeps `fromPayload()` tolerant of v0.10.x
        // payloads but still surfaces wrong-typed values. Migrate to
        // fromPayloadStrict() once every issuer emits `aud`.
        $aud = self::AUD_SERVER;
        if (array_key_exists('aud', $payload)) {
            if (!is_string($payload['aud'])) {
                throw new InvalidArgumentException('JWT claim "aud" must be a string.');
            }
            $aud = $payload['aud'];
        }

        return self::build($payload, $aud);
    }

    /**
     * Strict variant of {@see self::fromPayload()} that requires an explicit
     * `aud` claim and throws when it is absent, instead of defaulting it to
     * {@see self::AUD_SERVER}.
     *
     * Use this once all token issuers (server and hub `JwtHandler`) emit `aud`
     * so a missing audience is treated as a malformed token rather than
     * silently accepted as a legacy server-audience token.
     *
     * Performs **no** signature verification — see the class docblock.
     *
     * @param array<string, mixed> $payload Decoded token payload.
     *
     * @throws InvalidArgumentException When a required field (including `aud`)
     *                                  is missing or wrong-typed.
     */
    public static function fromPayloadStrict(array $payload): self
    {
        $aud = self::requireString($payload, 'aud', 'JWT claim');

        return self::build($payload, $aud);
    }

    /**
     * Build the DTO from a payload using a pre-resolved `aud`.
     *
     * @param array<string, mixed> $payload Decoded token payload.
     *
     * @throws InvalidArgumentException When a required field is missing or wrong-typed.
     */
    private static function build(array $payload, string $aud): self
    {
        $iss = self::requireString($payload, 'iss', 'JWT claim');
        $sub = self::requireString($payload, 'sub', 'JWT claim');
        $iat = self::requireInt($payload, 'iat', 'JWT claim');
        $exp = self::requireInt($payload, 'exp', 'JWT claim');
        $type = self::requireString($payload, 'type', 'JWT claim');

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
     * Optional fields (`nbf`, `jti`, `scope`, `serverId`) are **deliberately
     * omitted** when null/empty so v0.10.x decoders that pre-date them don't
     * see unexpected keys (wire-compat — must not change). This makes the
     * array output asymmetric versus the constructor, but the object
     * round-trip remains lossless: `fromPayload(toPayload($claims)) == $claims`
     * because `fromPayload()` re-applies the same defaults. See the class
     * docblock and the round-trip tests.
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
}
