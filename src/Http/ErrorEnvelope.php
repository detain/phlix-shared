<?php

/**
 * ErrorEnvelope.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Http;

/**
 * Canonical `{error, code}` JSON envelope body law (v0.57 wave).
 *
 * Lifted from the estate twins' identical emit lines: phlix-server
 * `src/Server/Http/Response.php` `error()` (:212) and phlix-hub
 * `src/Http/Response.php` `error()` (:230) both encode
 * `['error' => $message, 'code' => $code] + $extra` — the left-biased union
 * is the wire contract: `error`/`code` always lead and callers merge
 * additive top-level keys (`message`, `detail`, `denial_type`) after them.
 * The tripped-limiter pair is the W4 PREP seam: both hosts already CARRY it
 * as byte-identical per-repo twins — `rateLimitedBody()` at server
 * `Server/Http/Response.php:261` / hub `Http/Response.php:269`, and
 * `retryAfterHeader()` at server :270 / hub :280 — but carrying the twin and
 * importing this kernel are different claims: the SHAPE is adopted estate-wide,
 * the class itself is not yet consumed (adoption header below).
 *
 * ADOPTION STATUS — INERT (PENDING consumer adoption): `Phlix\Shared\Http`
 * has zero imports in the committed phlix-server `src/` tree and zero in the
 * committed phlix-hub `src/` tree (greps verified at this pass, 2026-11-27).
 * Both hosts still emit through their own private `rateLimitedBody()` /
 * `retryAfterHeader()` twins listed above; the server seam's own docblock
 * states the same law ("the seam now owns the vocabulary so S1 adoption of
 * the shared Envelope is mechanical"). This class is the single migration
 * target for that disclosed-and-pending consumer wave — until it lands,
 * treating any behavior here as host-live behavior is wrong.
 *
 * STATUS/header/JSON-encode glue stays per-repo — both `Response` classes
 * extend the Workerman response; this kernel owns the BODY shape and
 * the header VALUE formatting. Header ORDER law (Retry-After before
 * Content-Type) lives in the consumers' `Response::rateLimited()` and is
 * pinned by each repo's envelope corpus tests.
 *
 * Divergence vs the original design sketch: the sketched
 * `rateLimited(int $retryAfterSeconds)` / `maintenance(int ...)` signatures
 * are not expressible at body level — on neither host does the seconds value
 * ride the JSON body (it is a `Retry-After` HEADER only, RFC 9110 §10.2.3
 * delta-seconds). Inventing a body key would move wire bytes, so the kernel
 * splits: `build()`/`rateLimited()` take `array $extra`, and
 * `retryAfterHeader(int)` owns the header value. The hub's 503 maintenance
 * frame (`Application::maintenanceResponse()` :294-303) is a call-site
 * composition riding the same law — `build()` with host copy in `error` and
 * the hub's disambiguation `detail` additive; the words stay hub-branded,
 * the SHAPE is shared (the "same law as rateLimitResponse" ruling). The
 * server's legacy `errorPlain()` half-envelope (no `code`) is a deliberate
 * per-repo wire shape (pinned by phlix-server
 * `tests/Unit/Server/Http/InlineErrorEnvelopeAdoptionTest.php`) and is NOT
 * lifted here.
 *
 * @see \Phlix\Shared\RateLimit\RateLimitException for the trip this envelope answers.
 */
final class ErrorEnvelope
{
    /**
     * The stable machine code a tripped limiter answers with.
     *
     * PROVENANCE (phlix-shared-035): the literal below is a CITE-ONLY
     * mirror of the registered code `rate_limited` in the `@phlix/contracts`
     * error-code registry — declared in that repo's `src/errors.ts`,
     * emitted by its `scripts/emit-error-codes.mjs` into
     * `dist/error-codes.json` (the `codes` array; the order there is the
     * registry declaration order). Estate law: no new codes — the registry
     * is the SSOT; regens move only that file's md5-pinned family over in
     * phlix-contracts. Nothing here vendors the registry (dependency-churn
     * rule), and the registry's own tripwire
     * (`scripts/check-error-cites.mjs`, run as `npm run verify:cites`)
     * token-verifies cites against the phlix-server and phlix-hub working
     * trees only — it never scans this repo — so a registry rotation could
     * silently orphan this literal. The in-repo link is the dedicated pin
     * test `ErrorEnvelopeTest` (method named for the registry entry,
     * finding id cited in its docblock); together they are the tripwire.
     * If the registry ever renames or retires `rate_limited`, update the
     * registry, this constant, and that pin in one reconciliation.
     */
    public const string RATE_LIMITED_CODE = 'rate_limited';

    /**
     * Non-instantiable: static wire-shape kernel only.
     */
    private function __construct()
    {
    }

    /**
     * The canonical envelope body: `error` + `code` first, extra merged after.
     *
     * @param string               $message value for the `error` field (human/debug text)
     * @param string               $code    registered stable machine code
     * @param array<string, mixed> $extra   additional top-level keys, left-biased
     *                                      union AFTER `error`/`code`
     *
     * @return array<string, mixed>
     */
    public static function build(string $message, string $code, array $extra = []): array
    {
        return ['error' => $message, 'code' => $code] + $extra;
    }

    /**
     * The canonical tripped-limiter body (both twins' `rateLimitedBody()` verbatim).
     *
     * @param array<string, mixed> $extra additive top-level keys — the auth
     *                                      throttles merge a human `message`
     *                                      sentence here
     *
     * @return array<string, mixed>
     */
    public static function rateLimited(array $extra = []): array
    {
        return self::build('Too Many Requests', self::RATE_LIMITED_CODE, $extra);
    }

    /**
     * The single `Retry-After` formatter (both twins' `retryAfterHeader()` verbatim).
     *
     * The limiter/state layers already clamp the value non-negative; the wire
     * law is a plain integer string of delta-seconds.
     *
     * @param int $retryAfterSeconds seconds until the limiter window resets
     */
    public static function retryAfterHeader(int $retryAfterSeconds): string
    {
        return (string) $retryAfterSeconds;
    }
}
