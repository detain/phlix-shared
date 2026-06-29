<?php

declare(strict_types=1);

namespace Phlix\Shared\Support;

use InvalidArgumentException;

/**
 * Reusable payload assertion helpers for DTOs.
 *
 * @package Phlix\Shared\Support
 * @since 0.2.1
 */
trait PayloadAssert
{
    /**
     * @param array<string, mixed> $payload
     */
    protected static function requireString(array $payload, string $key, string $context): string
    {
        if (!array_key_exists($key, $payload)) {
            throw new InvalidArgumentException(sprintf('%s "%s" is required.', $context, $key));
        }

        $value = $payload[$key];

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s "%s" must be a string.', $context, $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected static function requireInt(array $payload, string $key, string $context): int
    {
        if (!array_key_exists($key, $payload)) {
            throw new InvalidArgumentException(sprintf('%s "%s" is required.', $context, $key));
        }

        $value = $payload[$key];

        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('%s "%s" must be an integer.', $context, $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected static function requireBool(array $payload, string $key, string $context): bool
    {
        if (!array_key_exists($key, $payload)) {
            throw new InvalidArgumentException(sprintf('%s "%s" is required.', $context, $key));
        }

        $value = $payload[$key];

        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('%s "%s" must be a boolean.', $context, $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected static function optionalString(array $payload, string $key, string $context, string $default = ''): string
    {
        if (!array_key_exists($key, $payload)) {
            return $default;
        }

        $value = $payload[$key];

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s "%s" must be a string.', $context, $key));
        }

        return $value;
    }
}
