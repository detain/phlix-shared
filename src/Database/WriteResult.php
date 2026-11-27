<?php

/**
 * WriteResult.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Database;

/**
 * The one place that knows what `Workerman\MySQL\Connection::query()` returns
 * for a WRITE statement, and the predicates every caller tests it with.
 *
 * Consolidation law (v0.57 leg M-28): `wroteNothing()` is lifted from the
 * server `Phlix\Common\Database\WriteResult` verbatim in semantics;
 * `affectedRows()` is the hub fold-in — two byte-identical privates
 * (`ClaimRequestHandler` and `RequestManager`) become this one public
 * narrowing. What each site DECIDES about a no-write (loss, refusal, or
 * "already current" success) is per-repo policy and stays at the call sites;
 * this class answers exactly the measurement questions, never the meaning.
 *
 * ## The measured contract
 *
 * Read straight out of `vendor/workerman/mysql/src/Connection.php::query()`
 * (dispatches on the FIRST space-delimited token of the trimmed SQL):
 *
 * | first token                     | returns                                       |
 * |---------------------------------|-----------------------------------------------|
 * | `select` / `show`               | `array` from `PDOStatement::fetchAll()`       |
 * | `update` / `delete` / `replace` | `int` from `PDOStatement::rowCount()`         |
 * | `insert`, rowCount > 0          | `string` from `PDO::lastInsertId()`           |
 * | `insert`, rowCount = 0          | **`null`** (falls out of the `if`)            |
 * | anything else                   | **`null`** (the `else` arm)                   |
 *
 * A genuine error never reaches any of those rows: `execute()` rethrows every
 * `PDOException` after one reconnect attempt, so a failed write arrives as a
 * **throw**, not as a return value.
 *
 * ### Trap 1 — `false` is a value this client does not produce
 *
 * There is no `return false` anywhere in `query()`. `PDO::lastInsertId()` is
 * declared `string|false`, but the MySQL driver answers with a string. So a
 * `$result === false` check is **simultaneously unreachable and blind to
 * `null`** — it can never fire, and it misses the one falsy value that IS
 * returned. That shape is what this class replaced. The `false` arm is kept
 * for defensive breadth (a driver swap, or a `lastInsertId()` that honors its
 * declared `false`), NOT because it is reachable today; do not "simplify" it
 * away on the grounds that nothing produces it.
 *
 * ### Trap 2 — a SUCCESSFUL insert can return the string `'0'`, which is FALSY
 *
 * Almost every Phlix primary key is a `CHAR(36)` UUID minted in PHP, with no
 * `AUTO_INCREMENT` column on the table. `PDO::lastInsertId()` therefore
 * answers `'0'` — and `'0'` is falsy in PHP. So the obvious "simplification"
 * `if (!$result)` reads a *successful* insert as a failure.
 * **Always test identity, never truthiness.**
 *
 * ### Trap 3 — the keyword match is whitespace-sensitive
 *
 * `explode(" ", $query)[0]` splits on a literal SPACE, so a statement whose
 * verb is followed by a newline rather than a space — `"INSERT\nINTO …"`, the
 * natural heredoc layout — does not match `'insert'` and falls to the `else`
 * arm, returning `null` for a write that succeeded. Reformatting SQL can
 * therefore move a site from one row of the table above to another without
 * changing a single semantic. This is why the `null` arm matters at sites
 * where the driver's own contract says `null` is impossible.
 *
 * @since 0.57.0
 */
final class WriteResult
{
    /**
     * Not instantiable — this is a namespaced predicate, not an object.
     */
    private function __construct()
    {
    }

    /**
     * True when a write statement demonstrably wrote nothing.
     *
     * Both arms are load-bearing and are pinned separately by
     * `tests/Database/WriteResultTest.php`:
     *
     *  - `null` is what the client actually returns for a zero-row INSERT and
     *    for any statement whose leading keyword it did not recognize;
     *  - `false` is the defensive arm described in the class docblock.
     *
     * Every other value — including the falsy string `'0'`, `int 0` from a
     * `rowCount()` and an empty array from a SELECT — is NOT "wrote nothing"
     * as far as this predicate is concerned. `'0'` in particular is a
     * SUCCESSFUL insert (trap 2 above); an `int 0` from a DELETE means the
     * statement ran and matched no rows, which is a different question this
     * predicate deliberately does not answer.
     *
     * @param mixed $result Whatever `Connection::query()` returned for a write.
     *
     * @return bool True when the statement demonstrably wrote nothing.
     */
    public static function wroteNothing(mixed $result): bool
    {
        return $result === false || $result === null;
    }

    /**
     * Narrow a DML result from the connection to an affected-rows int.
     *
     * A non-numeric or absent answer reads as `0` — the conservative side of
     * the single-winner law (`0` rows = the row is gone = lost the race, never
     * "won by mystery"). This is the fold-in of the hub's two byte-identical
     * privates; per the measured contract above, the numeric arms this sees
     * for real DML are `int` rowCount values (and the `null`/`false` shapes
     * `wroteNothing()` documents).
     *
     * @param mixed $result Value returned by `Connection::query()` for an UPDATE/DELETE.
     */
    public static function affectedRows(mixed $result): int
    {
        return is_numeric($result) ? (int) $result : 0;
    }
}
