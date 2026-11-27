<?php

/**
 * SettingsCodec.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Settings;

use InvalidArgumentException;
use JsonException;

/**
 * The settings-column value codec: the persisted-text representation of a
 * typed setting value, its decode, and the two pure reader primitives the
 * settings repositories grew independently.
 *
 * Consolidation law (v0.57 leg M-32, charter stricter-ships): the server and
 * hub settings repositories each carry a private `encode()`/`decode()` pair.
 * The DECODE arms are byte-identical between the repos. The ENCODE arms
 * diverge on one axis, and this kernel ships the hub's STRICTER posture:
 * the `json` arm (and the non-scalar default arm) uses
 * `json_encode($value, JSON_THROW_ON_ERROR)` — an unencodable value raises
 * `JsonException` and is NEVER persisted. The server's silent variant renders
 * `(string) json_encode($value)`, which stores an empty string for
 * unencodable payloads — the drift this leg exists to end. Consumer adoption
 * (server S1 1.8) is a DISCLOSED behavior change at that site: an
 * unencodable json value now throws inside `set()` and the PUT path must
 * catch it into its 400 envelope (red-first pin owed at adoption).
 *
 * `effective()` is the getEffective decision arm both repos implement
 * byte-identically, separated from the row-shape unwrap (`['value']`) which
 * is per-repo storage detail; `getEffectiveMany` stays per-repo orchestration
 * (it is DB I/O, not arithmetic). `clampValue()` generalizes the hub
 * resolvers' `clampInt` to the int|float domain, covering the estate's
 * scattered `max($lo, min($hi, $x))` bound sites (charter D-05).
 *
 * Vocabulary (S-shared-19 strict-arm law): `$valueType` is the settings
 * schema family — exactly `string|int|bool|float|json`. An unknown type
 * RAISES `InvalidArgumentException` in BOTH directions: the twins' silent
 * default-arm fall-through is ended by design, because a type this codec
 * does not implement is either programmer error or a corrupt/tampered
 * stored `value_type`, and settings must never silently pass through a
 * codec that does not understand them. The reject arm is unreachable from
 * legitimate traffic — every server admin write lands inside the arms via
 * `AdminSettingsController::mapSchemaType()` and the hub types each write
 * from its `HubSettingsRepository::ALLOWED_KEYS` map. The `json` DECODE
 * arm is likewise strict: `JSON_THROW_ON_ERROR` under a depth ceiling, so
 * a corrupt stored row raises `JsonException` instead of silently
 * decoding to `null` (the `bool` arm's documented silent-false storage
 * law is deliberately NOT swept up — see the HUB-ENVBOOL note on
 * `decode()`). Consumer relay row, disclosed: the server
 * `SettingsRepository::decode()` delegation and the hub override reads
 * have no catch at those call sites today, so a corrupt `json` row
 * surfaces as an exception at the read on re-vendor; any consumer-side
 * catch (or accepted loud-fail) is the consumer repo's own step, never a
 * codec concession.
 *
 * @since 0.57.0
 */
final class SettingsCodec
{
    /**
     * The exact `$valueType` vocabulary implemented by the arms — the sole
     * gate's accept-set; drift-pinned against the arms by the test suite.
     */
    private const array VALUE_TYPES = ['string', 'int', 'bool', 'float', 'json'];

    /**
     * Recursion ceiling for the strict `json` decode arm: PHP's own default
     * budget, the same number `Support\Json::MAX_INBOUND_DEPTH` pins for
     * inbound wire payloads — a stored settings value has no business
     * nesting deeper than an inbound request body.
     */
    private const int JSON_MAX_DEPTH = 512;

    /**
     * Not instantiable — this is a codec, not an object.
     */
    private function __construct()
    {
    }

    /**
     * Serialize a PHP value to its text representation for storage.
     *
     * @param mixed  $value     Value to encode.
     * @param string $valueType One of string|int|bool|float|json.
     *
     * @return string Text form suitable for the `setting_value` column.
     *
     * @throws JsonException          When the value cannot be JSON-encoded (never
     *                                 persists an empty string for an unencodable value).
     * @throws InvalidArgumentException When $valueType is outside the arms
     *                                 (S-shared-19: fail loud, never fall through).
     */
    public static function encode(mixed $value, string $valueType): string
    {
        self::assertValueType($valueType);

        return match ($valueType) {
            'bool'  => ($value ? '1' : '0'),
            'int'   => (string) (int) (is_numeric($value) ? $value : 0),
            'float' => (string) (float) (is_numeric($value) ? $value : 0),
            'json'  => json_encode($value, JSON_THROW_ON_ERROR),
            // The 'string' arm is the twins' old default body verbatim:
            // scalars cast, null (not scalar) JSON-encodes to 'null'.
            default => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR),
        };
    }

    /**
     * Decode a stored text value back into a PHP value per its type.
     *
     * HUB-ENVBOOL census row: the 'bool' limb is the PERSISTED-FORMAT decode
     * paired with encode()'s '1'/'0' — a storage round-trip, NOT an env read.
     * The tolerant strtolower==='true' leg exists for legacy rows; the estate
     * bool grammar's semantics (trim, caller default, junk warning) belong on
     * env knobs, never on how a column decodes, so this stays local by law.
     * Reachable vocabulary via the API is exactly '1'/'0'; garbage decodes
     * false silently, which is correct here (a corrupt stored row must not
     * flip a setting) and would be wrong for an operator-facing knob.
     *
     * The 'json' arm is STRICT (S-shared-19): malformed stored text RAISES
     * `JsonException` — with `JSON_THROW_ON_ERROR` in play a corrupt document
     * can no longer arrive as a silent `null` wearing the shape of a legal
     * one — under the `self::JSON_MAX_DEPTH` ceiling, so depth exhaustion
     * raises too instead of truncating. Whether the corrupt-row signal is
     * swallowed per read is each consumer's documented decision at its own
     * call site, never this codec's.
     *
     * @param string $raw       Stored text value.
     * @param string $valueType One of string|int|bool|float|json.
     *
     * @return mixed The decoded PHP value.
     *
     * @throws JsonException          When $valueType is 'json' and $raw is not a
     *                                 well-formed, ceiling-depth JSON document.
     * @throws InvalidArgumentException When $valueType is outside the arms.
     */
    public static function decode(string $raw, string $valueType): mixed
    {
        self::assertValueType($valueType);

        return match ($valueType) {
            'bool'  => $raw === '1' || strtolower($raw) === 'true',
            'int'   => (int) $raw,
            'float' => (float) $raw,
            'json'  => json_decode($raw, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR),
            default => $raw,
        };
    }

    /**
     * The getEffective decision arm: an override that EXISTS wins verbatim;
     * only an absent (null) override falls through to the default. `not null`
     * means a stored `false`/`0`/`''` is a real setting, never "unset".
     *
     * @param mixed $override  The stored override value, or null when absent.
     * @param mixed $default   The config-file/schema default.
     */
    public static function effective(mixed $override, mixed $default): mixed
    {
        return $override !== null ? $override : $default;
    }

    /**
     * Clamp a numeric value into inclusive bounds.
     *
     * The one-liner both repos repeat across their bound-check sites, on the
     * int|float domain (charter D-05: this is the shared shape; per-site
     * bounds VALUES remain each knob's own policy). No min>max guard is
     * invented: every original site trusted its own constants, and a
     * mis-ordered bound pair is a code bug the clamp should not launder.
     *
     * @param int|float $value Candidate value.
     * @param int       $min   Inclusive lower bound.
     * @param int       $max   Inclusive upper bound.
     *
     * @return int|float The bounded value: the input verbatim when in range,
     *                   otherwise one of the (int) bounds.
     */
    public static function clampValue(int|float $value, int $min, int $max): int|float
    {
        return max($min, min($max, $value));
    }

    /**
     * Fail-loud gate shared by both codec directions.
     *
     * @throws InvalidArgumentException When $valueType is outside the arms.
     */
    private static function assertValueType(string $valueType): void
    {
        if (in_array($valueType, self::VALUE_TYPES, true)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Unknown settings valueType "%s"; the codec vocabulary is exactly %s.',
            $valueType,
            implode('|', self::VALUE_TYPES),
        ));
    }
}
