<?php

/**
 * SettingsCodec.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Settings;

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
 * Vocabulary: `$valueType` is the settings schema family — one of
 * `string|int|bool|float|json` (unknown types fall through the default arms,
 * matching both twins).
 */
final class SettingsCodec
{
    /**
     * Not instantiable — this is a codec, not an object.
     */
    private function __construct()
    {
    }

    /**
     * Serialise a PHP value to its text representation for storage.
     *
     * @param mixed  $value     Value to encode.
     * @param string $valueType One of string|int|bool|float|json.
     *
     * @return string Text form suitable for the `setting_value` column.
     *
     * @throws JsonException When the value cannot be JSON-encoded (never
     *                        persists an empty string for an unencodable value).
     */
    public static function encode(mixed $value, string $valueType): string
    {
        return match ($valueType) {
            'bool'  => ($value ? '1' : '0'),
            'int'   => (string) (int) (is_numeric($value) ? $value : 0),
            'float' => (string) (float) (is_numeric($value) ? $value : 0),
            'json'  => json_encode($value, JSON_THROW_ON_ERROR),
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
     * @param string $raw       Stored text value.
     * @param string $valueType One of string|int|bool|float|json.
     *
     * @return mixed The decoded PHP value.
     */
    public static function decode(string $raw, string $valueType): mixed
    {
        return match ($valueType) {
            'bool'  => $raw === '1' || strtolower($raw) === 'true',
            'int'   => (int) $raw,
            'float' => (float) $raw,
            'json'  => json_decode($raw, true),
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
}
