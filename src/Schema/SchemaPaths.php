<?php

declare(strict_types=1);

namespace Phlix\Shared\Schema;

/**
 * Pure path resolver for the JSON files bundled under the package's
 * `schemas/` directory.
 *
 * Consumers (notably `phlix-server`) need to locate the bundled schema
 * and catalog files inside `vendor/detain/phlix-shared/schemas/` without
 * hardcoding the vendor path. This helper computes those absolute paths
 * relative to its own location, so the result is correct whether the
 * package is checked out for development or installed under `vendor/`.
 *
 * Honours the package's zero-I/O charter: every method here performs pure
 * string computation against `__DIR__`. It does NOT read, stat, or open
 * any file — callers decide whether and how to load the contents.
 *
 * @package Phlix\Shared\Schema
 * @since 0.7.0
 */
final class SchemaPaths
{
    /**
     * Absolute path to the bundled `schemas/` directory.
     *
     * `dirname(__DIR__, 2)` walks two levels up from `src/Schema/` to the
     * package root, then appends `/schemas`.
     *
     * @return non-empty-string Absolute filesystem path to the schemas dir.
     *
     * @since 0.7.0
     */
    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/schemas';
    }

    /**
     * Absolute path to the server-settings JSON Schema (draft 2020-12).
     *
     * @return non-empty-string Absolute path to `server-settings.schema.json`.
     *
     * @since 0.7.0
     */
    public static function serverSettings(): string
    {
        return self::dir() . '/server-settings.schema.json';
    }

    /**
     * Absolute path to the webhook event catalog data document.
     *
     * @return non-empty-string Absolute path to `webhook-events.json`.
     *
     * @since 0.7.0
     */
    public static function webhookEvents(): string
    {
        return self::dir() . '/webhook-events.json';
    }

    /**
     * Prevent instantiation — this class is a static path resolver only.
     */
    private function __construct()
    {
    }
}
