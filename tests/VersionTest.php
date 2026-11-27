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

    /**
     * phlix-shared-011: the README Status paragraph states an exact relation
     * between each shipped schema, its own census figures, and the git tag
     * named next to them — and these pins enforce that relation in both
     * directions. Before this step the prose was stale both ways: the cookie
     * trio was labeled "Unreleased" although v0.55.0 shipped it, and the 87-key
     * server census carried a "census of the working tree, not a v0.59.0
     * tag-time stamp" hedge although the file is byte-identical to that tag.
     * A hedge that lies is worse than no number, so the paragraph now commits
     * to checkable claims and the pins convict any prose/schema/tag drift.
     *
     * Census-serial law: the EXPECTED literals rotate from measured red at tip
     * in the same commit as the schema change AND the README paragraph — the
     * prose regexes below extract the same figures from the README, so prose
     * and schema can only move together. The git legs degrade to an honest
     * skip only in checkouts without the tag's objects (composer dist installs,
     * shallow clones); every census claim not needing git always runs.
     */
    private const EXPECTED_SERVER_KEYS = 87;
    private const EXPECTED_SERVER_RESTART_TRUE = 51;
    private const EXPECTED_SERVER_RESTART_FALSE = 36;
    private const EXPECTED_HUB_KEYS_TIP = 20;

    private const SERVER_SCHEMA_FILE = 'schemas/server-settings.schema.json';
    private const HUB_SCHEMA_FILE = 'schemas/hub-settings.schema.json';

    public function test_readme_server_schema_census_matches_tree_and_tag(): void
    {
        $schemaPath = self::repoFile(self::SERVER_SCHEMA_FILE);
        $this->assertFileExists($schemaPath, self::SERVER_SCHEMA_FILE . ' must ship at the package root.');
        $treeJson = (string) file_get_contents($schemaPath);

        $census = self::parseCensus($treeJson);
        if ($census === null) {
            $this->fail(
                self::SERVER_SCHEMA_FILE . ' must decode to an object whose every property carries a boolean `restart` flag.'
            );
        }
        [$keys, $trueCount, $falseCount] = $census;
        $this->assertSame(
            [self::EXPECTED_SERVER_KEYS, self::EXPECTED_SERVER_RESTART_TRUE, self::EXPECTED_SERVER_RESTART_FALSE],
            [$keys, $trueCount, $falseCount],
            'The working-tree server-schema census rotated: move the literals AND the README Status sentence in the same commit.'
        );

        $claim = self::readmeClaim(
            '/`schemas\/server-settings\.schema\.json` carries (\d+) keys \((\d+) `restart: true`, (\d+) `restart: false`\)/',
            'README Status must state the server census in the pinned sentence form (87 keys (51 ..., 36 ...) with a closed parenthetical).'
        );
        $this->assertSame(
            [$keys, $trueCount, $falseCount],
            [(int) $claim[1], (int) $claim[2], (int) $claim[3]],
            'The README server-schema figures must equal the schema census: prose and schema move together.'
        );

        $claim = self::readmeClaim(
            '/byte-identical between the working tip and the v(\d+\.\d+\.\d+) tag/',
            'README Status must state the tag-identity claim in the pinned sentence form.'
        );
        $tagJson = self::gitBlobAt('v' . (string) $claim[1], self::SERVER_SCHEMA_FILE);
        if ($tagJson === null) {
            $this->markTestSkipped(sprintf(
                'This checkout cannot answer git tag v%s (dist install or shallow clone); the tree/README legs above still ran.',
                $claim[1]
            ));
        }
        $this->assertSame(
            $treeJson,
            $tagJson,
            sprintf(
                'README claims %s is byte-identical to the v%s tag; the tag-time blob differs — rotate the Status sentence to name the real tag-time census.',
                self::SERVER_SCHEMA_FILE,
                $claim[1]
            )
        );
    }

    public function test_readme_hub_schema_census_matches_tree_and_tag(): void
    {
        $schemaPath = self::repoFile(self::HUB_SCHEMA_FILE);
        $this->assertFileExists($schemaPath, self::HUB_SCHEMA_FILE . ' must ship at the package root.');
        $tipKeys = self::parseKeyCount((string) file_get_contents($schemaPath));
        $this->assertSame(
            self::EXPECTED_HUB_KEYS_TIP,
            $tipKeys,
            'The working-tree hub-schema census rotated: move the literal AND the README Status sentence in the same commit.'
        );

        $claim = self::readmeClaim(
            '/`schemas\/hub-settings\.schema\.json` carries (\d+) keys/',
            'README Status must state the hub tip census in the pinned sentence form.'
        );
        $this->assertSame(
            $tipKeys,
            (int) $claim[1],
            'The README hub-schema tip figure must equal the schema census: prose and schema move together.'
        );

        $claim = self::readmeClaim(
            '/up from (\d+) at v(\d+\.\d+\.\d+) tag time/',
            'README Status must state the hub tag-time delta in the pinned sentence form (the tag-side drift the old hedge pretended was absent on the server leg is real here).'
        );
        $tagJson = self::gitBlobAt('v' . (string) $claim[2], self::HUB_SCHEMA_FILE);
        if ($tagJson === null) {
            $this->markTestSkipped(sprintf(
                'This checkout cannot answer git tag v%s (dist install or shallow clone); the tree/README legs above still ran.',
                $claim[2]
            ));
        }
        $this->assertSame(
            (int) $claim[1],
            self::parseKeyCount($tagJson),
            sprintf(
                'The README says the hub schema was %s keys at the v%s tag; the tag-time census differs.',
                $claim[1],
                $claim[2]
            )
        );
    }

    public function test_readme_cookie_trio_release_prose_matches_tag_history(): void
    {
        // The restart-flag paragraph dates the cookie trio to v0.55.0 and
        // claims that release's server schema is byte-identical to the current
        // one — where "current" is the 87-key census pinned by the Status
        // test. Both halves are git-verifiable; before this step the paragraph
        // still read "Unreleased" eleven releases after the trio shipped.
        $claim = self::readmeClaim(
            '/cookie-program trio \([^)]*\) shipped in v(\d+\.\d+\.\d+) — that release.s schema is byte-identical to the current one — bringing it to (\d+) keys/',
            'README restart-flag paragraph must state the trio release history in the pinned sentence form (no "Unreleased" for shipped keys).'
        );
        $this->assertSame(
            self::EXPECTED_SERVER_KEYS,
            (int) $claim[2],
            'The trio sentence\'s resulting key count must equal the pinned server census.'
        );

        $schemaPath = self::repoFile(self::SERVER_SCHEMA_FILE);
        $treeJson = (string) file_get_contents($schemaPath);
        $tagJson = self::gitBlobAt('v' . (string) $claim[1], self::SERVER_SCHEMA_FILE);
        if ($tagJson === null) {
            $this->markTestSkipped(sprintf(
                'This checkout cannot answer git tag v%s (dist install or shallow clone).',
                $claim[1]
            ));
        }
        $this->assertSame(
            $treeJson,
            $tagJson,
            sprintf('README claims the v%s server schema is byte-identical to the working tip; the blobs differ.', $claim[1])
        );
    }

    private static function repoFile(string $relative): string
    {
        return dirname(__DIR__) . '/' . $relative;
    }

    private static function readmeText(): string
    {
        $readme = self::repoFile('README.md');
        if (!is_file($readme)) {
            self::fail('README.md must exist at the package root.');
        }
        return (string) file_get_contents($readme);
    }

    /**
     * Collapse the README paragraph's hard-wrapping to single spaces so the
     * sentence-form regexes are line-break independent.
     */
    private static function normalizeSpaces(string $text): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $text);
        if ($collapsed === null) {
            self::fail('Whitespace collapse over the README must succeed.');
        }
        return $collapsed;
    }

    /**
     * Assert $pattern matches the normalized README exactly once and hand back
     * its capture groups. Passing the pattern as a runtime string keeps PHPStan
     * from specializing the match array into an `array{}|shape` union, so call
     * sites read captures directly; the exact-once check travels with each
     * site's message.
     *
     * @return array<array-key, string>
     */
    private static function readmeClaim(string $pattern, string $why): array
    {
        $matched = preg_match($pattern, self::normalizeSpaces(self::readmeText()), $claim);
        self::assertSame(1, $matched, $why);
        if (!is_array($claim)) {
            self::fail($why . ' (preg_match reported no match array.)');
        }
        return $claim;
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null [total, restart:true, restart:false]
     */
    private static function parseCensus(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        $properties = $decoded['properties'] ?? null;
        if (!is_array($properties)) {
            return null;
        }
        $trueCount = 0;
        $falseCount = 0;
        foreach ($properties as $entry) {
            $restart = is_array($entry) ? ($entry['restart'] ?? null) : null;
            if ($restart === true) {
                $trueCount++;
            } elseif ($restart === false) {
                $falseCount++;
            } else {
                return null;
            }
        }
        return [count($properties), $trueCount, $falseCount];
    }

    private static function parseKeyCount(string $json): ?int
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        $properties = $decoded['properties'] ?? null;
        return is_array($properties) ? count($properties) : null;
    }

    /**
     * The exact committed bytes of $path at tag $tag, or null when this
     * checkout cannot answer at all (no git, no such object). Callers skip the
     * tag leg honestly in that case; every non-git pin still runs.
     */
    private static function gitBlobAt(string $tag, string $path): ?string
    {
        if (!function_exists('exec') || !function_exists('shell_exec')) {
            return null;
        }
        $root = dirname(__DIR__);
        if (!is_dir($root . '/.git')) {
            return null;
        }
        $spec = $tag . ':' . $path;
        $exit = 0;
        exec('git -C ' . escapeshellarg($root) . ' cat-file -e ' . escapeshellarg($spec) . ' 2>/dev/null', $ignored, $exit);
        if ($exit !== 0) {
            return null;
        }
        $bytes = shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg($spec) . ' 2>/dev/null');
        return is_string($bytes) ? $bytes : null;
    }
}
