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
 * This class exists so `phlix-shared` v0.1.0 has a non-empty src/
 * tree — every CI tool, PHPStan, Psalm, and PHPUnit needs at least
 * one source file to chew on. Real interfaces and DTOs land in
 * v0.2.0 (Step B.3 of PHLIX_EXPANSION_PLAN.md).
 *
 * Keep this in sync with the git tag and the CHANGELOG entry.
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
    public const VERSION = '0.26.0';

    /**
     * Prevent instantiation — static marker only.
     *
     * @internal
     */
    private function __construct()
    {
    }
}
