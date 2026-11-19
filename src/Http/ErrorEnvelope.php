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
 * Canonical `{error, code}` JSON envelope body law (v0.57 leg 0.10 M-16, A-04).
 *
 * Lifted from the estate twins' identical emit lines: phlix-server
 * `src/Server/Http/Response.php` `error()` (:212) and phlix-hub
 * `src/Http/Response.php` `error()` (:230) both encode
 * `['error' => $message, 'code' => $code] + $extra` — the left-biased union
 * is the wire contract: `error`/`code` always lead and callers merge
 * additive top-level keys (`message`, `detail`, `denial_type`) after them.
 * The tripped-limiter pair is the W4 PREP seam both hosts already adopted:
 * `rateLimitedBody()` is byte-identical (hub `Http/Response.php:269`;
 * server `Server/Http/Response.php` twin), and `retryAfterHeader()` is the
 * single `Retry-After` formatter on both sides (hub :280-285).
 *
 * STATUS/header/JSON-encode glue stays per-repo — both `Response` classes
 * extend the Workerman response (G-14); this kernel owns the BODY shape and
 * the header VALUE formatting. Header ORDER law (Retry-After before
 * Content-Type) lives in the consumers' `Response::rateLimited()` and is
 * pinned by each repo's envelope corpus tests.
 *
 * Divergence vs the design note (SHARED-056-DESIGN.md §M-16): the sketched
 * `rateLimited(int $retryAfterSeconds)` / `maintenance(int ...)` signatures
 * are not expressible at body level — on neither host does the seconds value
 * ride the JSON body (it is a `Retry-After` HEADER only, RFC 9110 §10.2.3
 * delta-seconds). Inventing a body key would move wire bytes, so the kernel
 * splits: `build()`/`rateLimited()` take `array $extra`, and
 * `retryAfterHeader(int)` owns the header value. The hub's 503 maintenance
 * frame (`Application::maintenanceResponse()` :294-303) is a call-site
 * composition riding the same law — `build()` with host copy in `error` and
 * the HUB-49 disambiguation `detail` additive; the words stay hub-branded,
 * the SHAPE is shared (A-04: "same law as rateLimitResponse"). The server's
 * legacy `errorPlain()` half-envelope (no `code`) is a deliberate per-repo
 * wire shape (InlineErrorEnvelopeAdoptionTest pins) and is NOT lifted here.
 *
 * @see \Phlix\Shared\RateLimit\RateLimitException for the trip this envelope answers.
 */
final class ErrorEnvelope
{
    /**
     * The stable machine code a tripped limiter answers with. Owned by the
     * `@phlix/contracts` error-code registry — no new codes (estate law).
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
     *                                      sentence here (HUB-19)
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
