<?php

/**
 * Version.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared;

/**
 * Compile-time-constant package version marker.
 *
 * Introduced as the v0.1.0 placeholder so the package had a non-empty
 * `src/` tree for CI tooling (PHPStan, Psalm, PHPUnit) to chew on; the
 * real interface and DTO surface arrived from v0.2.0 on. Today the
 * class is purely a version marker: keep {@see self::VERSION} in sync
 * with the git tag and the CHANGELOG entry.
 *
 * @package Phlix\Shared
 * @since 0.1.0
 */
final class Version
{
    /**
     * Current package version (semver).
     *
     * @var non-empty-string
     */
    public const VERSION = '0.52.0';

    /**
     * Prevent instantiation — static marker only.
     *
     * @internal
     */
    private function __construct()
    {
    }
}
