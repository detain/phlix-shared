<?php

/**
 * Security: Secret Redactor — the estate's single redaction kernel.
 *
 * Promoted from `Phlix\Shared\Arr\SecretRedactor` (S-shared-10 /
 * phlix-shared-010). The old name remains importable: `src/Arr/SecretRedactor.php`
 * is now a `class_alias` shim, so every historical `Arr\` call-site keeps
 * working byte-identically.
 *
 * SCOPE TRUTH (phlix-shared-010, DOC-USE R-008): the original header claimed
 * this kernel guarded "triggerDownload/testConnection catch blocks". That was
 * never the full picture — the kernel also runs on EVERY error path the shared
 * *arr base raises (404 / 3xx / 4xx-5xx body excerpts via
 * {@see \Phlix\Shared\Arr\AbstractArrClient::request()}) and on the re-wrap
 * catches of all four concrete clients (Radarr/Sonarr/Prowlarr/Bazarr). The
 * note now says what the code does.
 *
 * WHY PATTERN ARMS (phlix-shared-010, SEC R-002): the exact-value kernel is
 * pattern-blind — it can only erase a secret the caller already holds as a
 * string. The server estate grew four bespoke redactors precisely because of
 * that gap (AdminSettingsController::maskSecrets, PluginAdminController::
 * redactSubmittedSecrets, SettingsMasker, MetadataHttpClient::redactUrl). The
 * `redactUri()`/`redactBearer()` arms below erase SECRET-SHAPED material
 * (`?apiKey=…`-style query values, `scheme://user:pass@` userinfo,
 * `Bearer`/`Basic`/`token` credential words) without needing the value in
 * hand; every arm still layers the exact-value kernel on top. Folding the
 * four bespoke copies onto this class is a later estate wave (S1/S2 adoption
 * pattern), not part of this promotion.
 *
 * These arms REPLACE values (string substitution over log text); they never
 * compare one, so the digest/comparison laws of this namespace do not apply
 * to them.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Security;

/**
 * Redacts secrets from log/error message text.
 *
 * @package Phlix\Shared\Security
 * @since 0.11.0 (Arr home); canonical Security home since S-shared-10
 *        (phlix-shared-010 — promotion + pattern arms + multi-secret arms).
 */
final class SecretRedactor
{
    /**
     * The literal every redacted value collapses to.
     */
    private const MASK = '***';

    /**
     * Query-string values whose key names a credential (value stops at the
     * next `&` or `#`; an EMPTY value is not a secret and stays untouched).
     */
    private const QUERY_SECRET_PATTERN =
        '/([?&])(api(?:key|_key)|access[_-]?token|password|passwd|secret|token|key)(=)([^&#]*)/i';

    /**
     * `scheme://user:password@` userinfo — the password leg only; a bare
     * `user@host` carries no secret and never matches.
     */
    private const USERINFO_PATTERN = '/(:\/\/[^:@\/\s]*:)[^@\/\s?#]+(@)/';

    /**
     * `Bearer`/`Basic`/`token` scheme words followed by a credential-shaped
     * value, with either a whitespace or a `:`/`=` separator.
     */
    private const BEARER_PATTERN = '/\b(bearer|basic|token)(\s*[:=]\s*|\s+)([A-Za-z0-9\-._~+\/]+=*)/i';

    /**
     * Characters that mark a matched `Bearer`-arm value as credential
     * material. A pure-letter value ("the bearer of bad news", "token of
     * approval") stays untouched — that entropy gate is what keeps ordinary
     * prose from being shredded. Known secrets are still erased by the
     * exact-value kernel layered on every arm.
     */
    private const BEARER_ENTROPY = '/[0-9\/+=.~_-]/';

    /**
     * Prevent instantiation — this class is a static utility only.
     */
    private function __construct()
    {
    }

    /**
     * Replaces every occurrence of each secret in the message with `***`.
     *
     * Secrets are applied LONGEST FIRST regardless of argument order: if one
     * secret is a substring of another (e.g. `KEY` inside `SECRETKEY`), the
     * shorter replacement running first would shred the longer value and
     * strand its residue in the message.
     * PHP 8's sort is stable, so equal-length secrets keep the caller's
     * relative order; the outcome is order-independent for them anyway.
     *
     * @param string $message The message potentially containing secrets.
     * @param string ...$secrets One or more secret strings to redact.
     * @return string The message with all secrets replaced by `***`.
     */
    public static function redact(string $message, string ...$secrets): string
    {
        $filteredSecrets = array_values(array_filter($secrets, static fn(string $s): bool => $s !== ''));
        if ($filteredSecrets === []) {
            return $message;
        }

        usort($filteredSecrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $replacements = array_fill(0, count($filteredSecrets), self::MASK);
        return str_replace($filteredSecrets, $replacements, $message);
    }

    /**
     * Redacts a URI/path: secret-SHAPED material first, known secrets after.
     *
     * Pattern arms (S-shared-10): credential-named query values
     * (`?apiKey=…`, `&token=…`, `&password=…`, …) and
     * `scheme://user:pass@` userinfo collapse to `***` even when the caller
     * does not hold the value; empty query values are left alone. The
     * exact-value kernel then erases any of `$secrets` still present.
     * A secret-free path passes through byte-identically — the shared *arr
     * base routes its 404/3xx messages through this arm precisely because a
     * caller-supplied `$path` can carry an `?apikey=`-shaped secret.
     *
     * @param string $uri The URI or path potentially carrying secrets.
     * @param string ...$secrets Known secret strings to erase as well.
     * @return string The redacted URI.
     *
     * @since S-shared-10 (phlix-shared-010)
     */
    public static function redactUri(string $uri, string ...$secrets): string
    {
        $masked = (string) preg_replace_callback(
            self::QUERY_SECRET_PATTERN,
            static fn(array $m): string => $m[4] === '' ? $m[0] : $m[1] . $m[2] . $m[3] . self::MASK,
            $uri
        );
        $masked = (string) preg_replace_callback(
            self::USERINFO_PATTERN,
            static fn(array $m): string => $m[1] . self::MASK . $m[2],
            $masked
        );

        return self::redact($masked, ...$secrets);
    }

    /**
     * Redacts message text: `Bearer`/`Basic`/`token`-shaped credentials
     * first, known secrets after.
     *
     * The scheme word and its separator survive; only the credential value
     * collapses to `***`. A value qualifies when it carries at least one
     * entropy character (digits, `+/=`, `.`/`_`/`-`/`~`) — real API keys,
     * base64 material and JWT legs always do; ordinary prose ("bearer of bad
     * news", "token of approval") deliberately does not, so a log line of
     * English is never mutilated. Pure-letter unknown secrets are outside
     * the pattern arm's reach by that choice — they are exactly what the
     * variadic `$secrets` kernel pass exists for.
     *
     * @param string $message The message potentially carrying scheme-shaped credentials.
     * @param string ...$secrets Known secret strings to erase as well.
     * @return string The redacted message.
     *
     * @since S-shared-10 (phlix-shared-010)
     */
    public static function redactBearer(string $message, string ...$secrets): string
    {
        $masked = (string) preg_replace_callback(
            self::BEARER_PATTERN,
            static function (array $m): string {
                if (preg_match(self::BEARER_ENTROPY, $m[3]) !== 1) {
                    return $m[0];
                }

                return $m[1] . $m[2] . self::MASK;
            },
            $message
        );

        return self::redact($masked, ...$secrets);
    }
}
