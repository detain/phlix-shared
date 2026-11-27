<?php

/**
 * JsonEnvelope.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Console;

use JsonException;

use function array_values;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The machine-readable `--json` CLI envelope as string kernels (v0.57 leg
 * 0.12, M-26 / C-19).
 *
 * Donor twins: phlix-server `src/Console/Commands/Concerns/JsonOutput.php`
 * and the hub-side `Phlix\Hub\Console\Commands\Concerns\JsonOutput` copy —
 * SRV-CONSOLE/HUB-CONSOLE (A-06) re-converged them to exact clones of each
 * other once both hosts folded their command-local `fail()` clones into the
 * shared trait. The payloads those traits hand to their private `encode()`
 * are the bytes lifted here:
 *
 *  - success → `{"ok":true,"data":[...]}`
 *  - failure → `{"ok":false,"error":"..."}`
 *
 * The key order is deliberate and is what the per-command tests and the
 * byte-matrix suites (`UserCommandOutputByteMatrixTest`) pin on both hosts.
 * `JSON_THROW_ON_ERROR` is deliberate: an unencodable value is a real bug
 * and must surface as a non-zero exit, never a silently truncated line.
 *
 * Kernel split (disclosed): the Symfony-bound trait glue — `isJsonMode()`,
 * the `fail()` exit-code chooser, `writeln()` — stays per-repo (this
 * package is PSR-only, no Symfony types), and `JSON_OUTPUT_MARKER` stays
 * per-repo on the traits (it marks the shipped compiled source of each
 * CLI; a package-resident marker would lie to the merge tooling). The
 * suite pins that negative law.
 *
 * @throws JsonException never thrown by this class itself — documented per
 *                       method for callers that build on the kernels.
 *
 * @since 0.57.0
 */
final class JsonEnvelope
{
    /**
     * The one flag set both traits already encode with.
     */
    private const int ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    private function __construct()
    {
    }

    /**
     * Render a success envelope. `$data` is normalised to a JSON list (the
     * `emitJsonSuccess` law) so the shape always matches
     * `{"ok":true,"data":[...]}` even for a single logical record or a
     * string-keyed map.
     *
     * @param array<array-key, mixed> $data Rows or records to expose under `data`.
     *
     * @throws JsonException when a value cannot be encoded (deliberate: bug, not truncation).
     */
    public static function success(array $data): string
    {
        return json_encode(
            [
                'ok' => true,
                'data' => array_values($data),
            ],
            self::ENCODE_FLAGS
        );
    }

    /**
     * Render a failure envelope carrying a single human/machine error
     * string (the `emitJsonError` law: `ok` first, then `error`).
     *
     * @throws JsonException never — the payload is two plain scalars.
     */
    public static function failure(string $error): string
    {
        return json_encode(
            [
                'ok' => false,
                'error' => $error,
            ],
            self::ENCODE_FLAGS
        );
    }
}
