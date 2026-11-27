<?php

/**
 * Jwt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Security;

use InvalidArgumentException;
use JsonException;

use function array_is_list;
use function explode;
use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function strlen;

/**
 * HS256 JWT mint/verify primitive — the estate's single algorithm pin.
 *
 * Canonical decode order is phlix-hub `src/Auth/JwtHandler::decode()`; the
 * label comparisons are the hardened phlix-server shape (S92-parity pin,
 * bug2): `is_string` + `hash_equals` on both `alg` and `typ` instead of loose
 * `!==` on a literal. The two parents converge on one law here, and both
 * arms are asserted with the SAME attack token in the vector suite:
 *
 *  1. three dot-separated segments or fail (`malformed`);
 *  2. decode the header with `JSON_THROW_ON_ERROR` — bad JSON is `malformed`,
 *     never a silent null that could sneak past an `?? null` probe; the
 *     segment must be canonical unpadded base64url, so malleable equivalent
 *     encodings (`+`, `/`, `=`) are `malformed` too (phlix-shared-007); and
 *     it must decode to a JSON object mapping — list segments, and the
 *     `{}`/`[]` empty-decode collapse, are `malformed` as well, never the
 *     mislabeling `alg-unexpected` (phlix-shared-031);
 *  3. pin `alg === 'HS256'` and, when present, `typ === 'JWT'` —
 *     BEFORE any signature work. This ordering is the whole point: an
 *     `alg:none`/`alg:HS512`-labeled token whose HMAC is mathematically
 *     correct (the attacker knows the scheme, just not the secret — and the
 *     pre-fix decoders would still have accepted a signed `none` label) is
 *     refused on what it CLAIMS, not on whether it PROVED itself;
 *  4. only then recompute the HMAC and `hash_equals` the signature
 *     (`signature-mismatch`);
 *  5. decode the payload under the same object-mapping law — a signed list
 *     payload is refused `malformed` there, not stripped by the string-key
 *     filter into empty claims (phlix-shared-031) — then keep string keys
 *     only (both parents' verbatim loop).
 *
 * Every rejection is a {@see JwtException} with one of exactly three
 * machine-readable reasons. Claim semantics (exp/nbf/aud/iss) are NOT
 * validated here — that stays in consumer pipelines and
 * {@see \Phlix\Shared\Auth\JwtClaims}.
 *
 * Secret floor: both parents reject secrets under 32 bytes at construction
 * ("JWT secret must be at least 32 bytes for HS256."). The floor is enforced
 * IN the primitive, one notch earlier than either parent, because a static
 * codec has no constructor to hide it in.
 *
 * @package Phlix\Shared\Security
 * @since 0.56.0
 */
final class Jwt
{
    /** The only algorithm this codec mints or accepts. */
    public const string ALGORITHM = 'HS256';

    /** The only `typ` label accepted when a header carries one. */
    public const string TYPE = 'JWT';

    /** Minimum secret length, bytes — HS256 key size, both parents' floor. */
    public const int MIN_SECRET_BYTES = 32;

    /**
     * Prevent instantiation — this class is a static utility only.
     */
    private function __construct()
    {
    }

    /**
     * Low-level assembly: base64url the two pre-serialized JSON segments and
     * stamp the HMAC over them. No JSON validation — {@see self::create()}
     * is the array-level seam that validates before it gets here.
     *
     * @param string $headerJson  Serialized JOSE header object.
     * @param string $payloadJson Serialized claims object.
     * @param string $secret      HMAC secret, at least 32 bytes.
     *
     * @throws InvalidArgumentException When the secret is below the HS256 floor.
     */
    public static function sign(string $headerJson, string $payloadJson, string $secret): string
    {
        self::assertSecret($secret);

        $headerEncoded = Base64Url::encode($headerJson);
        $payloadEncoded = Base64Url::encode($payloadJson);
        $signature = Base64Url::encode(
            hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", $secret, true),
        );

        return "{$headerEncoded}.{$payloadEncoded}.{$signature}";
    }

    /**
     * Mint a token from claim (and optional extra-header) arrays.
     *
     * The header is ALWAYS fixed `{"alg":"HS256","typ":"JWT"}` in that key
     * order — the mint side cannot be talked out of the algorithm.
     * `$extraHeader` may only ADD keys (e.g. `kid`); repeating `alg`/`typ`
     * with the fixed values is a no-op, and with any other value is a
     * programmer error rejected loudly at mint time.
     *
     * @param array<string, mixed> $claims      The payload to serialize.
     * @param string               $secret      HMAC secret, at least 32 bytes.
     * @param array<string, mixed>|null $extraHeader Additional header members.
     *
     * @throws InvalidArgumentException On a short secret or an alg/typ override attempt.
     * @throws JsonException              When claims/extra-header cannot be serialized.
     */
    public static function create(array $claims, string $secret, ?array $extraHeader = null): string
    {
        /** @var array<string, mixed> $header */
        $header = ['alg' => self::ALGORITHM, 'typ' => self::TYPE];

        /** @psalm-suppress MixedAssignment - $value is declared mixed by the param shape */
        foreach ($extraHeader ?? [] as $key => $value) {
            if ($key === 'alg' || $key === 'typ') {
                if ($header[$key] !== $value) {
                    throw new InvalidArgumentException(
                        'Jwt::create() cannot mint outside the fixed HS256/JWT header: '
                        . "attempted override of '{$key}'.",
                    );
                }

                continue;
            }

            /** @psalm-suppress MixedAssignment - absorbed by the array<string,mixed> target */
            $header[$key] = $value;
        }

        return self::sign(
            json_encode($header, JSON_THROW_ON_ERROR),
            json_encode($claims, JSON_THROW_ON_ERROR),
            $secret,
        );
    }

    /**
     * Verify `$token` under `$secret` and return its decoded parts.
     *
     * @param string $token The compact JWT.
     * @param string $secret The HMAC secret, at least 32 bytes.
     *
     * @throws JwtException On any rejection — see its three REASON_* constants.
     * @throws InvalidArgumentException When the secret is below the HS256 floor.
     */
    public static function verify(string $token, string $secret): JwtTokenParts
    {
        self::assertSecret($secret);

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw JwtException::malformed('Malformed JWT (expected 3 dot-separated segments).');
        }
        [$headerEncoded, $payloadEncoded, $signature] = $parts;

        /** @var array<array-key, mixed> $header */
        $header = self::decodeJsonObject($headerEncoded, 'header');
        $alg = $header['alg'] ?? null;
        if (!is_string($alg) || !hash_equals(self::ALGORITHM, $alg)) {
            throw JwtException::algUnexpected('Unexpected JWT alg (expected HS256).');
        }
        if (isset($header['typ'])) {
            $typ = $header['typ'];
            if (!is_string($typ) || !hash_equals(self::TYPE, $typ)) {
                throw JwtException::algUnexpected('Unexpected JWT typ (expected JWT).');
            }
        }

        $expectedSignature = Base64Url::encode(
            hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", $secret, true),
        );
        if (!hash_equals($expectedSignature, $signature)) {
            throw JwtException::signatureMismatch();
        }

        /** @var array<array-key, mixed> $decoded */
        $decoded = self::decodeJsonObject($payloadEncoded, 'payload');

        /** @var array<string, mixed> $payload */
        $payload = [];
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }

        return new JwtTokenParts($header, $payload);
    }

    /**
     * base64url-decode a segment and require it to be a JSON object mapping.
     *
     * The segment must be canonical unpadded base64url — the JWT-segment arm
     * {@see Base64Url::decodeJwtSegment()} (phlix-shared-007): `+`, `/` and
     * `=` encodings that decode to the same bytes under plain strict mode are
     * refused here, so byte-distinct equivalent tokens can no longer verify
     * identically. Then `JSON_THROW_ON_ERROR` (hub law) plus the object check
     * (both parents): a segment that is not a JSON object never survives to
     * the caller — phlix-shared-031 closes the hole in that parents' arm,
     * where `json_decode(..., true)` returns a PHP array for a JSON LIST too.
     * A list HEADER used to die as `alg-unexpected`, a label lying about a
     * segment with no `alg` member at all; an honestly-signed list PAYLOAD
     * passed the signature check and the string-key filter stripped its
     * integer keys into `payload = []` — an accepted credential with zero
     * claims, downstream fail-closed only by accident.
     *
     * `array_is_list()` refuses both shapes. It also refuses every segment
     * whose assoc decode collapses to the empty array, which is deliberate:
     * after `json_decode(..., true)` the JSON texts `{}` and `[]` are
     * indistinguishable, this mint serializes empty claims to the LIST form
     * anyway (`json_encode([])` is `[]`, never `{}`), and no consumer here
     * can use an `alg`-less header or a principal-less claim set — so the
     * whole empty class is `malformed` rather than silently survivable.
     * The ban is top-level only: a list as the VALUE of a named member
     * (`{"scope":["read","write"]}`) is ordinary claim material and stays
     * legal.
     *
     * @return array<array-key, mixed>
     *
     * @throws JwtException With reason `malformed`.
     */
    private static function decodeJsonObject(string $encoded, string $what): array
    {
        try {
            $decoded = json_decode(Base64Url::decodeJwtSegment($encoded), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw JwtException::malformed("JWT {$what} is not valid JSON.");
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw JwtException::malformed("JWT {$what} did not decode to a JSON object mapping.");
        }

        return $decoded;
    }

    /**
     * @throws InvalidArgumentException When the secret is below the HS256 floor.
     */
    private static function assertSecret(string $secret): void
    {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new InvalidArgumentException('JWT secret must be at least 32 bytes for HS256.');
        }
    }
}
