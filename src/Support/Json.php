<?php

declare(strict_types=1);

namespace Phlix\Shared\Support;

use InvalidArgumentException;
use JsonException;

/**
 * JSON Codec - Strict Encode Policy and Depth-Capped Inbound Decode
 *
 * One encode law for machine-written JSON, resolving the estate's flag
 * drift (DESIGN M-13, `D-05`): the hub encodes with `JSON_THROW_ON_ERROR`
 * everywhere while the server's plain path encodes silently and can emit
 * `false` into a string slot - a wire path that returns false instead of
 * throwing is exactly the bug class, so the shared default THROWS.
 *
 * The two wire baselines the repos actually run today are preserved as
 * explicit, named seams rather than silently unified:
 * - {@see encodePretty()} is the server HTTP response flag set verbatim
 *   (`Response::json()` - PRETTY | UNESCAPED_SLASHES | THROW_ON_ERROR). It
 *   MUST NOT propagate to the hub wire (the hub's REST is a compact machine
 *   contract, `M-HUB-09`), and the hub's compact set MUST NOT propagate to
 *   the server - the PRETTY delta stays;
 * - {@see encode()}'s default additionally carries UNESCAPED_UNICODE, the
 *   strict policy for NEW shared call sites. Repos adopting it at an existing
 *   wire site pass their live flag set explicitly; each such switch is a
 *   disclosed change, never a silent unify (DESIGN section 4).
 *
 * {@see decodeInbound()} answers the F-14 depth anarchy with one const
 * default (512 - PHP's own) while leaving the per-site policy choice (2/4/512
 * today) to the caller's explicit argument; malformed input is a `null` (the
 * caller's "bad request" seam), depth exhaustion is a THROWN `JsonException`
 * (a structural impossibility, not a bad-payload verdict).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 * @package   Phlix\Shared\Support
 * @since     0.56.0
 */
final class Json
{
    /**
     * Default maximum decode depth for inbound payloads - PHP's own default,
     * used when a call site does not state a stricter per-site policy.
     */
    public const int MAX_INBOUND_DEPTH = 512;

    /**
     * Encode with the shared strict policy: failure THROWS rather than
     * returning false (D-05 - a wire path that answers `false` into a string
     * slot is exactly the bug class). The throw is UNCONDITIONAL; `$flags`
     * selects style only, defaulting to the strict-unescaped pair.
     *
     * Repos adopting this at an EXISTING wire site pass their live style set
     * explicitly so bytes do not move silently; the "policy choice" rule of
     * M-13 applies per disclosed adoption, never by silent re-default.
     *
     * @param mixed $data  Anything json_encode() accepts.
     * @param int   $flags Style flags (JSON_THROW_ON_ERROR is added for you).
     *
     * @throws JsonException When the value is not encodable.
     */
    public static function encode(mixed $data, int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        $encoded = json_encode($data, $flags | JSON_THROW_ON_ERROR);

        // Unreachable when THROW_ON_ERROR is honored (the OR guarantees it
        // is); the is_string seam exists because the analyzer cannot see a
        // constant bitwise OR through a variable flag set. A false here would
        // be an engine contract violation - fail loud, never launder it.
        if (!is_string($encoded)) {
            throw new JsonException('json_encode returned false despite JSON_THROW_ON_ERROR.');
        }

        return $encoded;
    }

    /**
     * Encode with the SERVER HTTP response flag set verbatim - pretty,
     * unescaped slashes, throwing. Server wire baseline ONLY: the hub
     * response path stays compact ({@see encode()} with explicit flags).
     *
     * @throws JsonException When the value is not encodable.
     */
    public static function encodePretty(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Decode an inbound payload to an associative structure.
     *
     * Malformed JSON (syntax, invalid UTF-8) -> `null`: the caller maps it to
     * its own bad-input answer. Depth exhaustion -> `JsonException` is
     * RETHROWN: nesting beyond the cap is a structural impossibility for
     * legitimate payloads, and swallowing it would launder a hostile-deep
     * document into the same `null` as an honest typo.
     *
     * The two classes are told apart by the extension's STABLE depth message
     * (`'Maximum stack depth exceeded'`): when `JSON_THROW_ON_ERROR` is in
     * play, `json_last_error()` is NOT populated (probed on 8.3.6 - it reads
     * NONE after a thrown decode), so the exception message is the only
     * carrier. PHP counts the document root in the budget: k bracket levels
     * need a depth argument of k+1.
     *
     * A depth below 1 is REJECTED rather than mapped to PHP's "unlimited"
     * quirk - an inbound guard with no ceiling is not a guard.
     *
     * @throws JsonException Only for depth exhaustion - the structural class.
     */
    public static function decodeInbound(string $json, int $depth = self::MAX_INBOUND_DEPTH): mixed
    {
        if ($depth < 1) {
            throw new InvalidArgumentException(
                'Json::decodeInbound() requires a depth ceiling of at least 1;'
                . ' PHP\'s "0 or below means unlimited" quirk is rejected here by design.',
            );
        }

        try {
            // json_decode's engine accepts at most 2^31-1; a caller asking for more
            // means "unlimited" for every real payload, so clamping here is
            // unobservable - it only matches the bounded parameter signature.
            return json_decode($json, true, min($depth, 2147483647), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            if ($e->getMessage() === 'Maximum stack depth exceeded') {
                throw $e;
            }

            return null;
        }
    }

    private function __construct()
    {
    }
}
