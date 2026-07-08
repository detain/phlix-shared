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
     * Replaces every occurrence of each secret in the message with `***`.
     *
     * @param string $message The message potentially containing secrets.
     * @param string ...$secrets One or more secret strings to redact.
     * @return string The message with all secrets replaced by `***`.
     */
    public static function redact(string $message, string ...$secrets): string
    {
        $filteredSecrets = array_filter($secrets, static fn(string $s): bool => $s !== '');
        if ($filteredSecrets === []) {
            return $message;
        }

        $replacements = array_fill(0, count($filteredSecrets), '***');
        return str_replace($filteredSecrets, $replacements, $message);
    }
}
