<?php

/**
 * Uuid.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Database;

/**
 * Shared UUID v4 generator.
 *
 * Produces UUID v4 strings in the standard format:
 * `550e8400-e29b-41d4-a716-446655440000`
 *
 * The SHAPE matches the historical per-class generateUuid() implementations
 * scattered throughout the estate (8-4-4-4-12 hex, version 0x4000, variant
 * 0x8000). This kernel is the consolidation point: the server `Phlix\Common\Uuid`
 * mint and the hub `Phlix\Hub\Common\Support\Ids::uuidV4()` twin implement the
 * same algorithm and retire here on consumer adoption (v0.57 S1/S2 waves).
 * Erratum E-4 on record: the twins are SAME SHAPE, not claimed byte-identical
 * (server closes over a `BYTES = 16` constant, hub over the literal 16).
 *
 * ## The entropy source is the CSPRNG — deliberately (S443)
 *
 * These ids are primary keys (`media_items`, `users`, jobs …). They used to be
 * minted from `mt_rand()`, the process-global Mersenne Twister, which is NOT
 * private state: PHPUnit's runner calls `mt_srand(randomOrderSeed)` before a
 * random-ordered run (`phpunit.xml executionOrder="random"`), the order
 * `shuffle()` draws from the same stream, and individual tests re-pin it mid
 * run for their own determinism. Any of those makes every subsequent
 * `Uuid::v4()` byte-reproducible — so two same-seed runs (or two minting
 * consumers separated by a re-pin) mint the SAME CHAR(36) PK and the second
 * INSERT dies with MySQL 1062 `Duplicate entry … for key 'PRIMARY'`.
 * `random_bytes()` is a CSPRNG and cannot be steered by `mt_srand()`;
 * replaying the order seed can no longer replay an id.
 *
 * Determinism elsewhere is untouched: nothing in the estate may rely on seeded
 * UUID reproducibility, and jitter/retry randomness keeps drawing from
 * `mt_rand()` at its own call sites.
 */
final readonly class Uuid
{
    /** A UUID carries exactly 16 random bytes before the shape nibbles. */
    private const BYTES = 16;

    /**
     * The canonical shape {@see v4()} emits: lowercase 8-4-4-4-12 hex with the
     * version/variant nibbles. Transcode job ids (and every other `Uuid::v4()`
     * primary key) match this and nothing else has ever been minted, so it is
     * also the acceptance test for an id that arrived from a URL.
     *
     * Public here where the server twin kept it private: boundary checks and
     * fixture validators at the consumers want the ONE pattern, not a copy of
     * it (duplicated regexes are how shape laws drift).
     */
    public const V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /**
     * Generate a random UUID v4 string from the CSPRNG.
     *
     * @return string UUID in standard format xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     */
    public static function v4(): string
    {
        $bytes = random_bytes(self::BYTES);

        // RFC 4122: version 4 in the high nibble of time_hi_and_version,
        // variant 10xx in the top bits of clock_seq. Same shape the historical
        // sprintf('%04x…', mt_rand(0, 0x0fff) | 0x4000, …) produced, bit for bit.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * True when the value is exactly a canonical UUID v4 string.
     *
     * Boundary use: request params reach file paths verbatim after the
     * router's S435 rawurldecode, so a job id interpolated into a filesystem
     * path must first be PROVEN to be one (Parse don't validate) — a UUID
     * cannot contain a separator, a dot-dot, or a NUL.
     */
    public static function isV4(string $value): bool
    {
        return preg_match(self::V4_PATTERN, $value) === 1;
    }

    /**
     * Lowercase hex from the CSPRNG: exactly `2 * $bytes` characters.
     *
     * The S443 entropy law generalized into an API (charter design rows
     * E-13/D-07): every token the estate mints — OAuth state, PKCE verifier,
     * reset/invite tokens, correlation ids, relay tickets — must come from a
     * stream `mt_srand()` cannot steer, and every size must be expressible in
     * BYTES at one auditable surface. The server `Token::hex()` private and the
     * hub `Ids::token()` mint are the same one-liner over the same CSPRNG; on
     * adoption they delegate here rather than minting a third entropy seam.
     *
     * No runtime domain guard is invented: non-positive sizes fail loudly in
     * `random_bytes()` natively (ValueError), and both twins relied on exactly
     * that. The `int<1, max>` shape is the static-analysis contract.
     *
     * @param int<1, max> $bytes number of CSPRNG bytes to render
     */
    public static function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Prevent instantiation — static methods only.
     */
    private function __construct()
    {
    }
}
