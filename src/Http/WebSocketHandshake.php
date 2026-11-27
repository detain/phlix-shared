<?php

/**
 * WebSocketHandshake.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Http;

/**
 * String-level `Sec-WebSocket-Protocol` handshake kernels (v0.57 wave).
 *
 * Lifted from the estate twins' parse kit: phlix-server
 * `src/Server/WebSocket/SyncPlayAuthMiddleware.php` — `offersBearerSubprotocol()`
 * (:494) + `bearerSubprotocolToken()` (:519) + the consts (:68/:77) — and
 * phlix-hub `src/SyncPlay/SyncPlayRelayWorker.php` —
 * `negotiatedSubprotocolEcho()` (:1013) + the consts (:223/:231). The explode/
 * trim loops are byte-identical across hosts (the "Mirrors …" docblock claims
 * become real single-sourcing here); RFC 7230 per-entry comparison after OWS
 * trim: `bearer-chat` is a DIFFERENT protocol-id and does NOT count as an offer.
 *
 * Scope law: ONLY the string kernels move. The Workerman `Request`
 * adapter (`upgradeHeader()`, byte-identical bodies on both sides) stays
 * per-repo — its parameter is Workerman's Request class. The carrier
 * POLICY stays per-repo ON PURPOSE and is NOT unified: the server still
 * accepts the legacy `?token=` carrier behind `QueryTokenGatePolicy` while
 * the hub refuses it (the bearer-only law). The echo gate for the 101 answer
 * lives here so both hosts negotiate identically; a token sharing the
 * browser carrier is never a selection candidate — echoing it would put a
 * live credential on the RESPONSE wire (the exposure law).
 *
 * Disclosed kernel deltas (all behavior-preserving on real inputs):
 * `negotiatedSubprotocolEchoLine()` generalizes the hub's bearer-only echo to
 * any chosen id (the estate only ever chooses `bearer`); the returned `''`
 * replaces the twins' null/no-echo arm; the hub kernel accepted `mixed`
 * (header values arrive untyped) — the shared string-in signature moves the
 * `is_string()` coercion into the per-repo adapters, which already do it in
 * `upgradeHeader()`; the server's `?string` null-arm equals the shared `''`
 * arm at the kernel level (both mean "no offers").
 */
final class WebSocketHandshake
{
    /**
     * The single protocol-id both hosts implement (carrier marker).
     */
    public const string BEARER_SUBPROTOCOL = 'bearer';

    /**
     * The exact 101 answer line for the bearer marker (server const :77,
     * hub const :231 built the same bytes from the marker).
     */
    public const string BEARER_SUBPROTOCOL_ECHO = 'Sec-WebSocket-Protocol: bearer';

    /**
     * Non-instantiable: static string-kernel kit only.
     */
    private function __construct()
    {
    }

    /**
     * Whether a `Sec-WebSocket-Protocol` offer list includes the `bearer` marker.
     *
     * @param string $offersHeader raw header value ('' = absent)
     */
    public static function offersBearerSubprotocol(string $offersHeader): bool
    {
        if ($offersHeader === '') {
            return false;
        }

        foreach (explode(',', $offersHeader) as $protocolId) {
            if (trim($protocolId) === self::BEARER_SUBPROTOCOL) {
                return true;
            }
        }

        return false;
    }

    /**
     * The JWT carried by the `bearer` subprotocol carrier, or null when it holds none.
     *
     * Browser WebSocket APIs cannot set an Authorization header, so the
     * carrier form is `Sec-WebSocket-Protocol: bearer, <jwt>`: the `bearer`
     * entry is the marker and the first other non-empty entry is the
     * credential. Dumb on purpose — it does not gate on the marker being
     * offered; callers reach it only behind {@see offersBearerSubprotocol()}.
     *
     * @param string $offersHeader raw header value ('' = absent)
     */
    public static function bearerSubprotocolToken(string $offersHeader): ?string
    {
        if ($offersHeader === '') {
            return null;
        }

        foreach (explode(',', $offersHeader) as $entry) {
            $candidate = trim($entry);
            if ($candidate !== '' && $candidate !== self::BEARER_SUBPROTOCOL) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The 101 `Sec-WebSocket-Protocol` answer line, or '' for no echo.
     *
     * RFC 6455 §4.2.2 allows selecting AT MOST ONE offered protocol-id, and
     * §4.1 makes any selection the client did not offer a connection the
     * client must fail — so the chosen id must appear in the offer list.
     *
     * @param string $clientOffers raw offer list ('' = absent)
     * @param string $chosen       protocol-id to answer with
     */
    public static function negotiatedSubprotocolEchoLine(string $clientOffers, string $chosen): string
    {
        if ($clientOffers === '') {
            return '';
        }

        foreach (explode(',', $clientOffers) as $protocolId) {
            if (trim($protocolId) === $chosen) {
                return 'Sec-WebSocket-Protocol: ' . $chosen;
            }
        }

        return '';
    }
}
