<?php

/**
 * MigrationCorpusEqualityTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Database;

use Phlix\Shared\Database\MigrationChecksum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * v0.57 leg 0.12 (M-31) design pre-proof harness (SHARED-056-DESIGN R-3):
 * the shared `MigrationChecksum` kernel and the two consumers' own
 * `MigrationRunner::checksum` normalizers asserted equal over the FULL
 * migration corpus of both repos — not a hand-picked example set.
 *
 * Two-layer proof, per design:
 *  - HARVEST (committed as data): tests/Database/__fixtures__/MigrationChecksumCorpus.php
 *    holds, for every `migrations/*.sql` of both consumer repos, the raw
 *    file md5 plus the checksum produced by THAT CONSUMER'S OWN CODE at the
 *    generation tips (server private static via reflection; hub public).
 *    The equality shared≡consumer held for all rows at harvest time; the
 *    executing gate log lives beside the lane reports (V057B-m31-corpus.log).
 *  - LIVE (this suite, sibling-reachable only): every fixture row is
 *    re-read from the current tree; the raw-md5 pin reddens if any
 *    migration file was edited or added/removed since the harvest (a
 *    conscious re-harvest is then required), and the shared kernel is
 *    recomputed over the live bytes and asserted equal to the consumer's
 *    committed checksum. Reachability discipline is the
 *    SyncPlayDialectTest pattern: PHLIX_SERVER_REPO / PHLIX_HUB_REPO as
 *    explicit pointers, else a sibling checkout, else skip.
 */
#[CoversClass(MigrationChecksum::class)]
final class MigrationCorpusEqualityTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/__fixtures__/MigrationChecksumCorpus.php';

    /**
     * @return list<array{repo: string, relpath: string, raw_md5: string, consumer_checksum: string}>
     */
    private static function corpus(): array
    {
        /** @var list<array{repo: string, relpath: string, raw_md5: string, consumer_checksum: string}> $rows */
        $rows = require self::FIXTURE;

        return $rows;
    }

    public function testFixtureShapeCensusIsStable(): void
    {
        $rows = self::corpus();

        $byRepo = [];
        $keys = [];

        foreach ($rows as $row) {
            self::assertSame(
                ['repo', 'relpath', 'raw_md5', 'consumer_checksum'],
                array_keys($row),
                'Every corpus row carries exactly the four contracted fields.'
            );
            self::assertSame(1, preg_match('/^[0-9a-f]{32}$/', $row['raw_md5']));
            self::assertSame(1, preg_match('/^[0-9a-f]{32}$/', $row['consumer_checksum']));
            self::assertStringEndsWith('.sql', $row['relpath']);
            self::assertStringNotContainsString('/', $row['relpath']);

            $key = $row['repo'] . '/' . $row['relpath'];
            self::assertArrayNotHasKey($key, $keys, 'Corpus keys must be unique.');
            $keys[$key] = true;
            $byRepo[$row['repo']] = ($byRepo[$row['repo']] ?? 0) + 1;
        }

        self::assertSame(174, count($rows), 'Harvested corpus row count (130 server + 44 hub at generation tips).');
        self::assertSame(130, $byRepo['phlix-server'] ?? null);
        self::assertSame(44, $byRepo['phlix-hub'] ?? null);

        $order = array_map(static fn (array $r): string => $r['repo'] . '/' . $r['relpath'], $rows);
        $sorted = $order;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $order, 'Fixture rows are stored in strcmp order — re-harvest keeps them canonical.');
    }

    public function testServerKernelEqualsConsumerNormalizerLive(): void
    {
        $this->assertLiveRepoArm('phlix-server', 'PHLIX_SERVER_REPO');
    }

    public function testHubKernelEqualsConsumerNormalizerLive(): void
    {
        $this->assertLiveRepoArm('phlix-hub', 'PHLIX_HUB_REPO');
    }

    private function repoRoot(string $repo, string $envName): ?string
    {
        $env = getenv($envName);

        if ($env !== false && $env !== '') {
            $path = rtrim($env, '/');

            if (!is_dir($path . '/migrations')) {
                self::fail(sprintf(
                    '%s points at "%s" but "%s" is not a readable migrations directory — fix the pointer or unset it.',
                    $envName,
                    $env,
                    $path . '/migrations'
                ));
            }

            return $path;
        }

        $sibling = dirname(__DIR__, 3) . '/' . $repo;

        return is_dir($sibling . '/migrations') ? $sibling : null;
    }

    private function assertLiveRepoArm(string $repo, string $envName): void
    {
        $root = $this->repoRoot($repo, $envName);

        if ($root === null) {
            $this->markTestSkipped(
                'No ' . $repo . ' checkout reachable — set ' . $envName
                . ' or place the sibling repo beside phlix-shared.'
            );
        }

        $fixtureRows = array_values(array_filter(
            self::corpus(),
            static fn (array $r): bool => $r['repo'] === $repo
        ));

        $listed = array_map('basename', glob($root . '/migrations/*.sql') ?: []);
        sort($listed, SORT_STRING);
        $harvested = array_column($fixtureRows, 'relpath');

        self::assertSame(
            $harvested,
            $listed,
            $repo . ' migration corpus changed since the M-31 harvest — re-run the harvest script '
            . 'and commit the refreshed fixture (conscious re-harvest law).'
        );

        self::assertGreaterThan(0, count($fixtureRows));

        foreach ($fixtureRows as $row) {
            $bytes = file_get_contents($root . '/migrations/' . $row['relpath']);
            self::assertIsString($bytes);

            self::assertSame(
                $row['raw_md5'],
                md5($bytes),
                $repo . '/' . $row['relpath'] . ' bytes changed since the M-31 harvest — re-run it (drift tripwire).'
            );

            self::assertSame(
                $row['consumer_checksum'],
                MigrationChecksum::checksum($bytes),
                $repo . '/' . $row['relpath'] . ' hashes differently under the shared kernel than under the '
                . "consumer's own normalizer — kernel/consumer divergence must never ship."
            );
        }
    }
}
