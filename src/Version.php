<?php

declare(strict_types=1);

namespace Phlex\Shared;

/**
 * Compile-time-constant package version marker.
 *
 * This class exists so `phlex-shared` v0.1.0 has a non-empty src/
 * tree — every CI tool, PHPStan, Psalm, and PHPUnit needs at least
 * one source file to chew on. Real interfaces and DTOs land in
 * v0.2.0 (Step B.3 of PHLEX_EXPANSION_PLAN.md).
 *
 * Keep this in sync with the git tag and the CHANGELOG entry.
 *
 * @package Phlex\Shared
 * @since 0.1.0
 */
final class Version
{
    /**
     * Current package version (semver).
     *
     * @var non-empty-string
     */
    public const VERSION = '0.2.0';

    /**
     * Prevent instantiation — static marker only.
     *
     * @internal
     */
    private function __construct()
    {
    }
}
