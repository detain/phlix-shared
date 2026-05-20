<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests;

use Phlix\Shared\Version;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \Phlix\Shared\Version
 */
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
