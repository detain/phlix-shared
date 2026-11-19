<?php

declare(strict_types=1);

namespace Phlix\Shared\Support;

/**
 * Env Readers and the Bool Laws - Pure Config-Getters, One Estate Law
 *
 * The env-read idiom for config defaults, in one place, shared by both
 * hosts. `getenv('X') ?: $default` is the estate's oldest config bug: PHP's
 * `?:` treats the STRING `"0"` as falsy, so an operator who explicitly sets
 * `X=0` silently gets the DEFAULT instead (canonical statement: the pool
 * block of the server's `config/database.php`, generalized by the original
 * helper - `M-SERVER-34`, `A-18`). The two conventions config files rely on,
 * minus the trap:
 *
 * - unset, or set-but-empty (`""`), means "not configured" -> the default;
 * - `"0"` is an EXPLICIT value and survives.
 *
 * The helpers never throw: a config read cannot take the boot path down.
 * Callers own any further validation (bounds, enums).
 *
 * The bool family is the single law E-04's nine hand-rolled truthy lists
 * should have been calling ({@see boolValue()}). The trim is load-bearing:
 * the former copies disagreed on it, so `PHLIX_RELAY_DISABLED=" false"`
 * answered differently per site (E-04's drift #6) - under one law it answers
 * FALSE everywhere.
 *
 * Divergences from the server's repo-local original, both deliberate:
 * 1. the CPU-count probe (nproc shell-out + /proc/cpuinfo read) does NOT
 *    move - shell_exec and filesystem reads are charter-forbidden here, and
 *    DESIGN M-12 carves it out by name; the server keeps it local;
 * 2. the junk-value `error_log` warning does NOT move - this package's
 *    zero-I/O charter forbids logging side-effects (AGENTS.md). The shared
 *    answer for a value that is neither a true word nor a false word is the
 *    default, silently; the server copy keeps its one-line operator warning
 *    in front of the same verdict.
 *
 * The `(int)`-cast law of {@see integer()} ("12x" casts to 12, the old
 * sites' behavior, preserved) is a DISCLOSED divergence from the hub's
 * `is_numeric` guards, which reject "12x" and fall to their default - DESIGN
 * section 6 names the hub boot-behavior change at adoption time.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 * @package   Phlix\Shared\Support
 * @since     0.56.0
 */
final class EnvConfig
{
    /**
     * Read an integer knob: unset/empty -> $default, otherwise the value
     * cast the way `(int) getenv(...)` casts it (`"12x"` -> 12 - the old
     * sites' behavior, preserved).
     */
    public static function integer(string $name, int $default): int
    {
        $raw = getenv($name);

        return $raw === false || $raw === '' ? $default : (int) $raw;
    }

    /**
     * Read a string knob: unset/empty -> $default, otherwise the raw value -
     * including `"0"`, which `?:` used to swallow.
     */
    public static function text(string $name, string $default): string
    {
        $raw = getenv($name);

        return $raw === false || $raw === '' ? $default : $raw;
    }

    /**
     * Read an optional string knob whose "not configured" marker is `null`
     * (secrets/tokens a later gate validates): unset/empty -> null.
     */
    public static function nullableText(string $name): ?string
    {
        $raw = getenv($name);

        return $raw === false || $raw === '' ? null : $raw;
    }

    /**
     * The words the bool grammar reads as TRUE (case-folded, trimmed).
     *
     * @var list<string>
     */
    private const TRUE_WORDS = ['1', 'true', 'yes', 'on'];

    /**
     * The words the bool grammar reads as FALSE (case-folded, trimmed).
     *
     * @var list<string>
     */
    private const FALSE_WORDS = ['0', 'false', 'no', 'off'];

    /**
     * Read a boolean knob - the single law E-04's nine hand-rolled truthy
     * lists should have been calling.
     *
     * Grammar (identical for every bool reader here, see
     * {@see boolValue()}): unset/empty -> $default; true words -> true; false
     * words -> false; anything else -> $default.
     */
    public static function bool(string $name, bool $default = false): bool
    {
        return self::boolValue(getenv($name), $default, $name);
    }

    /**
     * Read an OPT-OUT-style knob: a bool whose default is ON and which the
     * operator turns off by spelling a false word (the PHLIX_RELAY_ENABLED
     * shape). Same grammar as {@see bool()}; its own verb so the knob's
     * intent reads at the call site instead of a bare `, true)` argument.
     */
    public static function boolOptOut(string $name, bool $default = true): bool
    {
        return self::boolValue(getenv($name), $default, $name);
    }

    /**
     * The bool grammar as a pure function over an ALREADY-READ raw value.
     *
     * Public because a caller that receives the raw env value from its own
     * caller still owes the same law - the server's `RelayTunnelBoot`
     * gate is exactly that seam (start.php runs outside PHPUnit, so the
     * relay boot gate is pinned by injecting raw values, not by putenv).
     *
     * Unlike the server copy, junk answers carry no `error_log` warning
     * here: the zero-I/O charter of this package forbids logging
     * side-effects (the verdict - the default - is identical).
     *
     * @param string|false|null $raw     Raw env value; `getenv()`'s false and null both read as unset.
     * @param bool              $default Answer for unset/empty/junk.
     * @param string            $name    Knob name; the silent shared law does not
     *                                   use it - kept call-compatible with the
     *                                   server original's warning-bearing signature.
     */
    public static function boolValue(string|false|null $raw, bool $default, string $name): bool
    {
        if (!is_string($raw)) {
            return $default;
        }

        $normalized = strtolower(trim($raw));

        if ($normalized === '') {
            return $default;
        }

        if (in_array($normalized, self::TRUE_WORDS, true)) {
            return true;
        }

        if (in_array($normalized, self::FALSE_WORDS, true)) {
            return false;
        }

        return $default;
    }

    private function __construct()
    {
    }
}
