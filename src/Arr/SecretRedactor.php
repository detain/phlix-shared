<?php

/**
 * Secret Redactor.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Arr;

/**
 * Tiny utility to redact secrets from log messages.
 *
 * Prevents API keys from leaking into log files when exceptions are caught
 * and their messages are logged in triggerDownload/testConnection catch blocks.
 *
 * @package Phlix\Shared\Arr
 * @since 0.11.0
 */
final class SecretRedactor
{
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
     * strand its residue in the message (review shared__srphp Minor #4).
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

        $replacements = array_fill(0, count($filteredSecrets), '***');
        return str_replace($filteredSecrets, $replacements, $message);
    }
}
