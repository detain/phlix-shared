<?php

/**
 * MigrationChecksum.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Database;

use function implode;
use function md5;
use function preg_match;
use function preg_split;
use function rtrim;

/**
 * The migrations-ledger checksum kernel (v0.57 leg 0.12, M-31 / C-22).
 *
 * Twin provenance — byte-identical bodies, authored twice: phlix-server
 * `src/Common/Database/MigrationRunner::checksum()` (private static, the
 * SV-4.9 review finding 3 lineage: keeping the rewrite-class header the 076
 * protocol asks operators to maintain must NOT flip the checksum and
 * trigger a spurious one-time re-apply on the next boot) and phlix-hub
 * `src/Common/Database/MigrationRunner::checksum()` (public static, whose
 * own docblock says "Ported verbatim (algorithm) from phlix-server's
 * MigrationRunner (SV-4.9)").
 *
 * The normalization is deliberately narrow and CANNOT mask a real SQL
 * change: it only drops lines that are ENTIRELY a `--`/`#` comment (after
 * leading whitespace) and per-line trailing whitespace. Any change to an
 * actual SQL token — including an inline `-- ...` comment appended to a
 * real statement line — is preserved in the hash, so a genuine edit still
 * diverges the checksum and re-applies (safely, migrations are re-run
 * safe). Trailing-newline awareness is part of the shipped law: the final
 * newline yields an empty last line that rides the hash.
 *
 * Adoption gate (disclosed): real databases hold rows already hashed by
 * the twins, so the per-repo delegation legs (design 1.x/2.x, PUSH-GATED)
 * must first prove corpus-wide equality over every shipped `.sql`
 * (charter §4-6 static-corpus harness — `normalize()` is public exactly so
 * it can diff host corpora); the ledger rehearsal against real MySQL stays
 * a consumer-side pre-delegation gate, not this kernel's proof burden.
 *
 * @since 0.57.0
 */
final class MigrationChecksum
{
    private function __construct()
    {
    }

    /**
     * The checksum text a ledger row's hash is taken over.
     */
    public static function normalize(string $sql): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $sql);
        if ($lines === false) {
            return $sql;
        }

        $kept = [];
        foreach ($lines as $line) {
            // Drop full-line `--` / `#` comments (after any leading whitespace).
            if (preg_match('/^\s*(--|#)/', $line) === 1) {
                continue;
            }
            $kept[] = rtrim($line);
        }

        return implode("\n", $kept);
    }

    /**
     * Compute the ledger checksum of a migration file's contents: md5 over
     * the normalized text — byte-ident to both twins on every input,
     * including the (unreachable for these patterns) split-failure arm,
     * which md5s the raw text on both hosts.
     */
    public static function checksum(string $sql): string
    {
        return md5(self::normalize($sql));
    }
}
