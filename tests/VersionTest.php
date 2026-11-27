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

    /**
     * phlix-shared-022 (S-shared-21): historical release tags cut without a
     * CHANGELOG heading, each verified heading-less at its own commit on
     * 2026-11-27 — the date the census arm below went live. Verbatim history
     * forbids retro-inserting release sections, so the gaps live here, dated
     * and non-growable. v0.55.1 is the finding's own tag (annotated, peels to
     * eba4a21, the direct child of the v0.55.0 cut; tag-time Version::VERSION
     * was still '0.55.0' and its schema-copy change is narrated under
     * [0.57.0]) — its record is the dated erratum under [Unreleased] in
     * CHANGELOG.md. The four June–July 2026 cuts (all ancestors of master)
     * predate this repository's release-ritual discipline.
     */
    private const HEADING_EXEMPT_TAGS = ['v0.55.1', 'v0.7.1', 'v0.8.1', 'v0.9.2', 'v0.17.0'];

    /**
     * phlix-shared-022 (S-shared-21): the release-ritual heading check.
     *
     * AGENTS.md "Versioning" puts the git tag in a four-way lockstep with
     * Version::VERSION, the CHANGELOG heading, and the README stamp. The
     * sibling pins above cover the working-tip triangle; nothing here could
     * see the past, and the census this step ran shows what that let
     * through: v0.55.1 was tagged with only the tag leg moved, and four
     * older tags shipped the same way. This guard makes the ritual
     * executable in three arms:
     *
     *  1. release-ritual arm — the exact shape the finding prescribes:
     *     `git tag --points-at HEAD`. On the day a cut is tagged, its
     *     heading must already be in the file at that very commit — the
     *     arm matches against the CHANGELOG.md blob committed at HEAD
     *     (gitBlobAt), never the working-tree file, so a heading added
     *     only to an uncommitted working copy cannot smuggle a
     *     heading-less cut past the ritual. A mid-cycle untagged HEAD
     *     leaves this arm quiet by design.
     *  2. census arm — every version-shaped tag the checkout can see must
     *     own a `## [x.y.z]` heading unless it is one of the five dated
     *     historical gaps in HEADING_EXEMPT_TAGS. No guard may fabricate a
     *     heading for a shipped release; it pins the record and refuses
     *     every new gap.
     *  3. exemption-integrity arm — the skip list is two-directional: an
     *     entry whose tag has vanished from the checkout reddens too, so
     *     the list can neither silently widen nor rot.
     *
     * Vacuity posture follows the census-guard house idiom: a CHANGELOG
     * exposing zero version headings fails loud (wrong or emptied file).
     * The git legs degrade to an honest skip only where git cannot answer
     * at all — dist installs and shallow fetches — exactly as the sibling
     * tag pins do; every non-git leg still runs.
     */
    public function test_every_version_tag_carries_a_changelog_heading(): void
    {
        $changelogPath = __DIR__ . '/../CHANGELOG.md';
        $this->assertFileExists($changelogPath, 'CHANGELOG.md must exist at the package root.');
        $contents = (string) file_get_contents($changelogPath);

        $headingTotal = preg_match_all('/^## \[\d+\.\d+\.\d+\]/m', $contents, $headingMatches);
        if ($headingTotal === false) {
            $this->fail('preg_match_all over CHANGELOG.md must succeed.');
        }
        if ($headingTotal === 0) {
            $this->fail(
                'CHANGELOG.md exposes zero version headings — the release-ritual census has no ground truth to check against; refusing vacuous green.'
            );
        }

        $allTags = self::gitTagNames();
        $headTags = self::gitTagNames('HEAD');
        if ($allTags === null || $headTags === null) {
            $this->markTestSkipped(
                'This checkout cannot answer git tags (dist install or shallow clone); the working-tip lockstep pins above still ran.'
            );
        }

        $versionTags = array_values(array_filter(
            $allTags,
            static fn (string $tag): bool => preg_match('/^v\d+\.\d+\.\d+$/', $tag) === 1
        ));
        if ($versionTags === []) {
            $this->markTestSkipped(
                'git answers, but this checkout carries no version tags (a shallow fetch stopped short of them); the census arm needs tag objects to mean anything.'
            );
        }

        $uncited = [];
        foreach ($versionTags as $tag) {
            if (in_array($tag, self::HEADING_EXEMPT_TAGS, true)) {
                continue;
            }
            if (!self::changelogHasHeading($contents, $tag)) {
                $uncited[] = $tag;
            }
        }
        $this->assertSame(
            [],
            $uncited,
            'Every release tag must carry a matching `## [x.y.z]` heading in CHANGELOG.md (AGENTS.md Versioning lockstep). A historical cut that genuinely shipped without one belongs in VersionTest::HEADING_EXEMPT_TAGS only with a dated record — never in a retro-inserted heading.'
        );

        $staleExemptions = array_values(array_diff(self::HEADING_EXEMPT_TAGS, $versionTags));
        $this->assertSame(
            [],
            $staleExemptions,
            'HEADING_EXEMPT_TAGS names tags this checkout cannot see — retire each exemption deliberately in the same commit that removes the tag, or the list stops being a census of real gaps.'
        );

        $headContents = self::gitBlobAt('HEAD', 'CHANGELOG.md');
        $ritualMisses = [];
        foreach ($headTags as $tag) {
            if (preg_match('/^v\d+\.\d+\.\d+$/', $tag) !== 1) {
                continue;
            }
            if (in_array($tag, self::HEADING_EXEMPT_TAGS, true)) {
                continue;
            }
            if ($headContents === null) {
                $this->markTestSkipped(
                    'A version tag points at HEAD, but this checkout cannot read the CHANGELOG.md blob committed at HEAD; the cut-time arm refuses to fall back to the working-tree file.'
                );
            }
            if (!self::changelogHasHeading($headContents, $tag)) {
                $ritualMisses[] = $tag;
            }
        }
        $this->assertSame(
            [],
            $ritualMisses,
            'A tag pointing at HEAD was cut without its `## [x.y.z]` CHANGELOG heading present at this commit — the exact defect class of v0.55.1 (phlix-shared-022).'
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

    /**
     * Tag names visible to this checkout — `git tag -l`, restricted to those
     * pointing at $pointsAt when given (the shape `git tag --points-at HEAD`
     * the release ritual prescribes). Null when git cannot answer at all,
     * mirroring gitBlobAt's degradation law.
     *
     * @return list<string>|null
     */
    private static function gitTagNames(?string $pointsAt = null): ?array
    {
        if (!function_exists('exec')) {
            return null;
        }
        $root = dirname(__DIR__);
        if (!is_dir($root . '/.git')) {
            return null;
        }
        $command = 'git -C ' . escapeshellarg($root) . ' tag -l';
        if ($pointsAt !== null) {
            $command .= ' --points-at ' . escapeshellarg($pointsAt);
        }
        $lines = [];
        $exit = 0;
        exec($command . ' 2>/dev/null', $lines, $exit);
        if ($exit !== 0) {
            return null;
        }
        return array_values(array_filter(
            $lines,
            static fn (string $line): bool => $line !== ''
        ));
    }

    /**
     * True when CHANGELOG.md carries the `## [x.y.z]` heading for the
     * version-shaped tag name $tag. The heading must start a line; released
     * sections use `-` or `—` date separators, both of which live behind the
     * closing bracket, so the match stops there.
     */
    private static function changelogHasHeading(string $contents, string $tag): bool
    {
        $matched = preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $parts);
        if ($matched !== 1) {
            self::fail(sprintf('The heading census received a non-version-shaped tag name: %s', $tag));
        }
        $heading = preg_match('/^## \[' . preg_quote($parts[1], '/') . '\]/m', $contents);
        return $heading === 1;
    }
}
