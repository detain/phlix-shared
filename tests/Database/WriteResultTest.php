<?php

/**
 * WriteResult Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Database;

use Phlix\Shared\Database\WriteResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The value table for {@see WriteResult::wroteNothing()} and the affected-rows
 * narrowing — ported from the server twin's suite (S131 lineage), minus the
 * per-repo delegate-leg pin (that belongs to the server scanner's adoption).
 *
 * Every row is asserted on its own, because that is the failure mode this
 * predicate has already had once: the private ancestor's `|| $result === null`
 * arm was deleted in review r3 and an entire selection stayed byte-identically
 * GREEN, because no double in the suite could produce `null`. A suite that
 * only ever feeds one falsy value leaves the other arm dead in test.
 */
#[CoversClass(\Phlix\Shared\Database\WriteResult::class)]
final class WriteResultTest extends TestCase
{
    /**
     * The two arms, and the values that must NOT be mistaken for them.
     *
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function resultValues(): array
    {
        return [
            // ── the two arms ───────────────────────────────────────────────
            'null — a zero-row INSERT, or an unrecognised leading keyword' => [null, true],
            'false — the defensive arm; this client never produces it' => [false, true],

            // ── the falsy value that means SUCCESS ─────────────────────────
            "'0' — lastInsertId() on a CHAR(36) PK table: a SUCCESSFUL insert" => ['0', false],

            // ── everything else ────────────────────────────────────────────
            "'42' — lastInsertId() on an AUTO_INCREMENT table" => ['42', false],
            '0 (int) — rowCount() for a DELETE that matched nothing' => [0, false],
            '1 (int) — rowCount() for a DELETE that removed one row' => [1, false],
            "'' — an empty string is falsy but is not a no-write signal" => ['', false],
            '[] — fetchAll() on a SELECT that matched nothing' => [[], false],
            'true — what several older test doubles answer for a write' => [true, false],
        ];
    }

    #[DataProvider('resultValues')]
    public function testTheValueTableIsPinnedRowByRow(mixed $result, bool $expected): void
    {
        $this->assertSame(
            $expected,
            WriteResult::wroteNothing($result),
            'the insert-result contract moved: '
            . var_export($result, true)
            . ' must ' . ($expected ? '' : 'NOT ')
            . 'be read as "wrote nothing"',
        );
    }

    /**
     * The single most important row, restated as its own named test so a
     * regression here cannot hide inside a data-provider summary line.
     *
     * `PDO::lastInsertId()` answers `'0'` on a table with no `AUTO_INCREMENT`
     * column, which is nearly every Phlix table (`CHAR(36)` UUIDs minted in
     * PHP). `'0'` is falsy, so `if (!$result)` reads a SUCCESSFUL insert as a
     * failure. This predicate must not repeat that mistake.
     */
    public function testTheFalsyStringZeroIsASuccessfulInsertNotAFailure(): void
    {
        $this->assertFalse(
            WriteResult::wroteNothing('0'),
            "'0' is what a successful INSERT into a CHAR(36)-PK table returns; "
            . 'reading it as "wrote nothing" reports every such write as a failure',
        );

        // The trap itself — `(bool) '0' === false` — is a language fact, fixed for
        // every supported PHP 8.x, so it is recorded as rationale rather than
        // asserted; the live check above is the one that can actually redden if
        // the predicate ever learns the mistake.
    }

    /**
     * `int 0` from a DELETE's `rowCount()` is a DIFFERENT question, and this
     * predicate deliberately does not answer it.
     *
     * "wrote nothing because the write did not happen" (`null`) and "ran fine
     * and matched no rows" (`int 0`) are not the same event. Sites that care
     * about the second must test it themselves (or use affectedRows()).
     */
    public function testAnIntZeroRowCountIsNotWroteNothing(): void
    {
        $this->assertFalse(WriteResult::wroteNothing(0));
        $this->assertTrue(WriteResult::wroteNothing(null));
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function rowCounts(): array
    {
        return [
            "'1' string rowCount" => ['1', 1],
            "'0' — the falsy SUCCESS string narrows to 0 rows" => ['0', 0],
            "'42' string" => ['42', 42],
            '42 int' => [42, 42],
            '0 int' => [0, 0],
            'null — the no-write shape reads 0' => [null, 0],
            'false — the defensive shape reads 0' => [false, 0],
            'true reads 0 (not numeric)' => [true, 0],
            "'abc' reads 0" => ['abc', 0],
            "'' reads 0" => ['', 0],
            '[] reads 0' => [[], 0],
            '1.0 float is numeric — narrows to 1' => [1.0, 1],
            "'2.7' is numeric — (int) truncates to 2" => ['2.7', 2],
        ];
    }

    #[DataProvider('rowCounts')]
    public function testAffectedRowsNarrowsDmlResults(mixed $result, int $expected): void
    {
        $this->assertSame($expected, WriteResult::affectedRows($result));
    }

    /**
     * The two predicates partition the contract: `wroteNothing` keeps its
     * identity law (false|null), `affectedRows` reads the SAME shapes
     * conservatively (0). A row that is not a number is never a win.
     */
    public function testTheTwoPredicatesDisagreeByDesignOnNull(): void
    {
        $this->assertTrue(WriteResult::wroteNothing(null));
        $this->assertSame(0, WriteResult::affectedRows(null));
    }
}
