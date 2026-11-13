<?php

/**
 * Version Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests;

use Phlix\Shared\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(\Phlix\Shared\Version::class)]
final class VersionTest extends TestCase
{
    public function test_version_is_valid_semver(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[a-z0-9\.\-]+)?$/',
            Version::VERSION,
            'Version::VERSION must be a valid semver string.'
        );
    }

    public function test_version_matches_changelog_heading(): void
    {
        $changelogPath = __DIR__ . '/../CHANGELOG.md';
        $this->assertFileExists($changelogPath, 'CHANGELOG.md must exist at the package root.');

        $contents = (string) file_get_contents($changelogPath);
        // Look for a heading line of the form `## [VERSION]` to verify version sync.
        $expected = '## [' . Version::VERSION . ']';
        $this->assertStringContainsString(
            $expected,
            $contents,
            sprintf(
                'CHANGELOG.md must contain a "%s" section that matches Version::VERSION.',
                $expected
            )
        );
    }

    /**
     * LW-014 [review shared__ipd Minor #2]: the release lockstep covers the
     * README stamps too (AGENTS.md "Versioning": Version::VERSION = CHANGELOG
     * heading = git tag = README status/install stamps), and until this test
     * the README half had no guard — v0.53.0 shipped a README still stamped
     * v0.52.0. A release that forgets the README now reddens the suite.
     */
    public function test_readme_status_stamp_matches_version(): void
    {
        $readmePath = __DIR__ . '/../README.md';
        $this->assertFileExists($readmePath, 'README.md must exist at the package root.');

        $contents = (string) file_get_contents($readmePath);

        $stamp = '**v' . Version::VERSION . '**';
        $this->assertStringContainsString(
            $stamp,
            $contents,
            sprintf(
                'README.md must carry the "%s" status stamp that matches Version::VERSION (release lockstep).',
                $stamp
            )
        );
    }

    /**
     * LW-014: the copy-pasteable install line must carry the caret constraint
     * for the current major.minor. Below 1.0 the caret does not admit the next
     * minor, so a stale line silently pins every new adopter to the previous
     * release.
     */
    public function test_readme_install_constraint_matches_version_minor(): void
    {
        $readmePath = __DIR__ . '/../README.md';
        $this->assertFileExists($readmePath, 'README.md must exist at the package root.');

        $contents = (string) file_get_contents($readmePath);

        $parts = explode('.', Version::VERSION);
        $constraint = '^' . $parts[0] . '.' . $parts[1];
        $needle = 'composer require detain/phlix-shared:' . $constraint;

        $this->assertStringContainsString(
            $needle,
            $contents,
            sprintf(
                'README.md must carry the "%s" install constraint matching Version::VERSION (release lockstep).',
                $needle
            )
        );
    }

    public function test_constructor_is_private(): void
    {
        $reflection = new ReflectionClass(Version::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor, 'Version must declare a constructor.');
        $this->assertTrue(
            $constructor->isPrivate(),
            'Version::__construct must be private to prevent instantiation.'
        );

        // Exercise the constructor through reflection so static-analysis
        // coverage reflects the (intentionally inert) body. Bypasses the
        // `private` modifier so the test still runs on stricter PHP runtimes.
        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor->setAccessible(true);
        $constructor->invoke($instance);
        $this->assertInstanceOf(Version::class, $instance);
    }
}
