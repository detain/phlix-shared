<?php

/**
 * UpsertSyntax Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Database;

use Phlix\Shared\Database\UpsertSyntax;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the hub twin (HUB-S14 lineage): the upsert SQL is portable
 * across MySQL 8.0.19+ (row alias) and MariaDB / older MySQL (VALUES()).
 * The hub's repository-emits leg stays per-repo (it mocks that repo); the
 * pure legs and a direct rewrite() pin live here.
 */
#[CoversClass(\Phlix\Shared\Database\UpsertSyntax::class)]
final class UpsertSyntaxTest extends TestCase
{
    private const SQL = "INSERT INTO t (a, b) VALUES (:a, :b)\n   AS new_row\n   ON DUPLICATE KEY UPDATE "
        . 'a = new_row.a, '
        . 'b = t.b + new_row.b, c = GREATEST(t.c, new_row.c)';

    protected function tearDown(): void
    {
        UpsertSyntax::reset();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function versions(): array
    {
        return [
            'mysql 8.0.46'        => ['8.0.46', true],
            'mysql 8.0.19'        => ['8.0.19', true],
            'mysql 8.4.1'         => ['8.4.1-0ubuntu0.24.04.1', true],
            'mysql 8.0.18'        => ['8.0.18', false],
            'mysql 5.7'           => ['5.7.44', false],
            'mariadb 10.6'        => ['10.6.18-MariaDB-0ubuntu0.22.04.1', false],
            'mariadb rpl prefix'  => ['5.5.5-10.11.6-MariaDB', false],
            'mariadb 11'          => ['11.4.2-MariaDB', false],
            'unparseable'         => ['weird', true],
        ];
    }

    #[DataProvider('versions')]
    public function testFlavorDetection(string $version, bool $alias): void
    {
        self::assertSame($alias, UpsertSyntax::supportsAlias($version));
        UpsertSyntax::detect($version);
        self::assertSame($alias, UpsertSyntax::usesAlias());
    }

    public function testMysqlKeepsRowAlias(): void
    {
        UpsertSyntax::detect('8.0.46');
        self::assertSame(self::SQL, UpsertSyntax::adapt(self::SQL));
    }

    public function testMariaDbUsesValuesFunctionWithoutAlias(): void
    {
        UpsertSyntax::detect('10.6.18-MariaDB');
        $out = UpsertSyntax::adapt(self::SQL);
        self::assertStringNotContainsString('new_row', $out);
        self::assertStringNotContainsString('AS ', $out);
        self::assertStringContainsString('a = VALUES(a)', $out);
        self::assertStringContainsString('b = t.b + VALUES(b)', $out);
        self::assertStringContainsString('GREATEST(t.c, VALUES(c))', $out);
        self::assertStringContainsString(":b)\n   ON DUPLICATE KEY UPDATE", $out);
    }

    /**
     * rewrite() is the pure core: the stateless surface must answer exactly
     * what adapt() would under each flavor, with no process state involved.
     */
    public function testRewriteIsPureForBothFlavors(): void
    {
        self::assertSame(self::SQL, UpsertSyntax::rewrite(self::SQL, true));

        $out = UpsertSyntax::rewrite(self::SQL, false);
        self::assertStringNotContainsString('new_row', $out);
        self::assertStringContainsString('a = VALUES(a)', $out);

        // And under a DETECTED mariadb flavor, adapt() and rewrite(?, false)
        // agree byte-for-byte — the static memo only selects the arm.
        UpsertSyntax::detect('10.6.18-MariaDB');
        self::assertSame($out, UpsertSyntax::adapt(self::SQL));
    }

    public function testTheAliasVocabularyIsPinned(): void
    {
        self::assertSame('new_row', UpsertSyntax::ALIAS);
    }
}
