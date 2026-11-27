<?php

/**
 * MigrationChecksum Test.
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
 * Characterization of the migration ledger checksum (v0.57 leg 0.12, M-31).
 * Twins: phlix-server src/Common/Database/MigrationRunner::checksum()
 * (private static, :847 — SV-4.9 review finding 3 lineage) and phlix-hub
 * src/Common/Database/MigrationRunner::checksum() (public static, :605 —
 * its own docblock: "Ported verbatim (algorithm) from phlix-server's
 * MigrationRunner (SV-4.9)"). Bodies are byte-identical; only visibility
 * and prose differ. The two hosts' migration ledgers hold real rows hashed
 * by these functions — adoption (S1/S2 delegation legs) is gated on the
 * charter §4-6 static-corpus equality proof, NOT on this kernel alone.
 */
#[CoversClass(MigrationChecksum::class)]
final class MigrationChecksumTest extends TestCase
{
    public function testRealSqlRidesTheRawNormalizedText(): void
    {
        // Trailing-newline awareness is part of the shipped twin law: the
        // final newline yields an (rtrimmed) empty last line that rides the
        // hash. Pinning it exactly as both runners compute today.
        self::assertSame(md5("SELECT 1;\n"), MigrationChecksum::checksum("SELECT 1;\n"));
        self::assertSame(md5('SELECT 1;'), MigrationChecksum::checksum('SELECT 1;'));
        self::assertSame(md5(''), MigrationChecksum::checksum(''));
    }

    public function testFullLineCommentBothDialectsAreDropped(): void
    {
        // SV-4.9: doc-only edits must not flip the ledger checksum and
        // trigger a spurious re-apply.
        self::assertSame(
            MigrationChecksum::checksum("SELECT 1;"),
            MigrationChecksum::checksum("-- keep the 076 header current\nSELECT 1;")
        );
        self::assertSame(
            MigrationChecksum::checksum("SELECT 1;"),
            MigrationChecksum::checksum("#   indented hash comment\nSELECT 1;")
        );
    }

    public function testTrailingWhitespacePerLineIsStripped(): void
    {
        self::assertSame(
            MigrationChecksum::checksum("SELECT 1;\n"),
            MigrationChecksum::checksum("SELECT 1;   \n")
        );
    }

    public function testNewlineStyleIsIrrelevant(): void
    {
        $lf = "ALTER TABLE a\nADD COLUMN b INT;";
        $crlf = "ALTER TABLE a\r\nADD COLUMN b INT;";
        $cr = "ALTER TABLE a\rADD COLUMN b INT;";

        self::assertSame(MigrationChecksum::checksum($lf), MigrationChecksum::checksum($crlf));
        self::assertSame(MigrationChecksum::checksum($lf), MigrationChecksum::checksum($cr));
    }

    public function testInlineCommentOnASqlLineSurvivesTheHash(): void
    {
        // The narrow normalization CANNOT mask a real change: an inline
        // "-- ..." appended to a statement line still diverges.
        self::assertNotSame(
            MigrationChecksum::checksum("SELECT 1;"),
            MigrationChecksum::checksum("SELECT 1; -- appended")
        );
    }

    public function testGenuineSqlEditsDiverge(): void
    {
        self::assertNotSame(
            MigrationChecksum::checksum("SELECT 1;"),
            MigrationChecksum::checksum("SELECT 2;")
        );
    }

    public function testBlankLineStructureIsPreserved(): void
    {
        // Only comment lines are DROPPED; blank lines ride (rtrim to ""),
        // so statement spacing stays observable in the hash.
        self::assertNotSame(
            MigrationChecksum::checksum("A;\nB;"),
            MigrationChecksum::checksum("A;\n\nB;")
        );
    }

    public function testNormalizeIsPublicForTheEqualityHarness(): void
    {
        // The charter §4-6 static-corpus proof diffs normalized text of
        // every migration file across hosts; it needs the kernel exposed.
        self::assertSame("A;\n", MigrationChecksum::normalize("-- c\nA;\n"));
        self::assertSame('A;', MigrationChecksum::normalize("  # c\rA;   "));
    }

    public function testChecksumEqualsMd5OfNormalizeOnEveryShape(): void
    {
        foreach (["A;\n", '-- x', "\rA;\r\n", '', "A\r\nB -- keep\n"] as $sql) {
            self::assertSame(
                md5(MigrationChecksum::normalize($sql)),
                MigrationChecksum::checksum($sql),
                'checksum must be md5 over normalize for: ' . json_encode($sql)
            );
        }
    }
}
