<?php

/**
 * Security: OriginPolicy — the CSRF-origin brain kernel (pure decision law).
 *
 * The estate's cookie-auth safety law is written twice, verbatim-equivalent:
 * phlix-server `src/Auth/CookiePolicy.php` and phlix-hub
 * `src/Hub/CookiePolicy.php` (HUB-C004 "the single place CSRF law lives";
 * the server twin declares the same intent). This kernel owns the PURE
 * decision surface — both classes' `originIsSameSite()`,
 * `stateChangingRequestOriginAllowed()`, `isHttpsForwarded()`,
 * `isLocalhostHost()` and `custodyMode()` verbatim-identical — so the two
 * roots can never drift on the law while keeping their genuinely different
 * stateful arms repo-side.
 *
 * What deliberately does NOT move (charter + honest repo context):
 *  - `cookieModeEnabled()` / `insecureOverride()` / `cookiesSecure()` /
 *    `warnProductionInsecureOnce()` — they read the shared settings schema
 *    (server `SettingsRepository`, hub `HubSettingsResolvers` closures) and
 *    env, and the hub's production-marker trio is hub deployment identity;
 *    the stateful callers stay thin delegators per repo (DESIGN M-09);
 *  - cookie NAMES: the two roots serve same-host different-port deployments
 *    with INTENTIONALLY distinct names (`phlix_session`/`phlix_refresh` vs
 *    `phlix_hub_token`/`phlix_hub_refresh`) — a shared constant would invite
 *    exactly the jar-clobber the split exists to prevent.
 *
 * Divergences found while folding (DESIGN O-1 answer: preserve each side's
 * recorded answer; none are law conflicts):
 *  1. `custodyMode()` — the server carries a `$formEraBrowser` limb (its
 *     legacy form login alias + `application/x-www-form-urlencoded`
 *     content-type detection); the hub retired SSR forms, so its browser
 *     signal is origin/Sec-Fetch only. The kernel PARAMETERS the limb:
 *     the hub side delegates with `false`, which is its exact old
 *     expression (lossless — pinned in the vector corpus).
 *  2. `originIsSameSite()` — the hub reads Host through `(string)` casts;
 *     the server early-returns false when the Host is absent/unparseable.
 *     Verdict-identical on every reachable input (a null/'' Host can never
 *     equal a non-empty origin host — brute-force proved on the corpus);
 *     the server arm ships, with the null-origin limb documented as
 *     always-false (the parents' signature takes a non-null string).
 *  3. Header access: the parents read via their request objects; this
 *     kernel takes raw strings (and a headers map for the
 *     `X-Forwarded-Proto` convenience limb) so request adapters never
 *     reach the wire layer here.
 *
 * Never collapse `originIsSameSite()` (ISSUANCE — deliberately LENIENT:
 * lowercased hosts, default-port normalization, http(s)-scheme
 * whitelisting) with `stateChangingRequestOriginAllowed()` (REQUEST LAW —
 * deliberately STRICT: raw byte-exact host comparison, both-or-neither
 * ports, no default normalization). Both parents keep the pair split for
 * the reason M-SERVER-76 caught once already: merging the axes silently
 * loosens the guard. The empty-string Origin keep-quirk (present-but-''
 * skips the origin arm; only the literal `null` Origin is refused outright)
 * is ratified-and-pinned in both repos — it ships unchanged.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Security;

/**
 * Pure origin/custody decision law over strings and bools — zero I/O,
 * zero ambient reads (the request adapters supply every value).
 *
 * @package Phlix\Shared\Security
 */
final class OriginPolicy
{
    /** Cookie custody class — mirrors contracts COOKIE_MODE.COOKIE (src/auth.ts). */
    public const MODE_COOKIE = 'cookie';

    /** Bearer custody class — mirrors contracts COOKIE_MODE.BEARER (src/auth.ts). */
    public const MODE_BEARER = 'bearer';

    /** Header name the forwarded-proto limb reads (case-insensitive lookup). */
    public const FORWARDED_PROTO_HEADER = 'X-Forwarded-Proto';

    /** Hostnames that satisfy the §2.4 localhost limb (any port). */
    private const LOCALHOST_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * State-changing verb set, exact-case (server `RequestAuthenticator`
     * line 179 / hub `CsrfOriginGuard` line 56 — byte-identical lists;
     * `HEAD`/`OPTIONS` deliberately absent: they carry no body).
     */
    private const STATE_CHANGING_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

    /**
     * §2.4 scheme law over a request headers map: any case-insensitive
     * occurrence of X-Forwarded-Proto whose first hop reads https. The
     * value law itself lives in {@see httpsForwardedValue()} (the parents'
     * static, which callers holding the string directly reuse).
     *
     * @param array<array-key, mixed> $headers Raw header map (non-string
     *        values are not headers this kernel knows; skipped).
     */
    public static function httpsForwarded(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (strtolower((string) $name) !== strtolower(self::FORWARDED_PROTO_HEADER)) {
                continue;
            }

            return self::httpsForwardedValue($value);
        }

        return false;
    }

    /**
     * First-hop https test on an X-Forwarded-Proto value (chains append:
     * "https, http" — the client-facing hop wins; both parents verbatim).
     */
    public static function httpsForwardedValue(?string $forwardedProto): bool
    {
        if (!is_string($forwardedProto) || $forwardedProto === '') {
            return false;
        }

        $first = strtolower(trim(explode(',', $forwardedProto)[0]));

        return $first === 'https';
    }

    /**
     * Hostname limb of the §2.4 localhost rule: exact-name match after
     * lowercasing and :port strip (bracketed IPv6 literals kept whole).
     */
    public static function localhostHost(?string $host): bool
    {
        if (!is_string($host) || $host === '') {
            return false;
        }

        return in_array(self::hostOf($host), self::LOCALHOST_HOSTS, true);
    }

    /**
     * LENIENT issuance-side same-site test (LABELS a cookie; never guards
     * a request — see the class law above): http/https scheme only
     * (app://, capacitor://, tizen://, file:// are device webview vaults
     * → bearer), lowercased bracket-stripped hosts, both ports normalized
     * through the origin's default port. Server arm; a null Origin (or a
     * null/absent/unparseable Host) is always false.
     */
    public static function originIsSameSite(?string $origin, ?string $host): bool
    {
        if (!is_string($origin)) {
            return false;
        }

        $parts = parse_url($origin);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $originHost = strtolower($parts['host'] ?? '');
        $originHost = str_starts_with($originHost, '[') && str_ends_with($originHost, ']')
            ? substr($originHost, 1, -1)
            : $originHost;
        if ($originHost === '') {
            return false;
        }

        // (Server arm was `is_string($host) ? parse_url(...) : false` plus
        // the is_array refusal — restructured into guards for type flow;
        // every input's verdict is unchanged, the sweep proved parity.)
        if (!is_string($host)) {
            return false;
        }

        $hostParts = parse_url('//' . $host);
        if (!is_array($hostParts)) {
            return false;
        }

        $requestHost = self::hostOf($host);
        if ($originHost !== $requestHost) {
            return false;
        }

        $originPort = $parts['port'] ?? self::defaultPort($scheme);
        $requestPort = $hostParts['port'] ?? self::defaultPort($scheme);

        return $originPort === $requestPort;
    }

    /**
     * The state-changing verb predicate (both parents' inline expressions
     * promoted to law: server `RequestAuthenticator`, hub
     * `CsrfOriginGuard`/`FederationController`). Exact-case: the HTTP
     * layer's verbs are RFC-strict uppercase.
     */
    public static function stateChangingMethod(string $method): bool
    {
        return in_array($method, self::STATE_CHANGING_METHODS, true);
    }

    /**
     * The §2.5 CP-009 belt as a tri-state: `cross-site` (trim + lowercase,
     * both parents' normalization) → false (decided: refuse). Everything
     * else — same-origin, same-site, none, junk, missing — → null (no
     * signal; the caller falls through to the header arms). The belt
     * REJECTS the exact promise "cross-site" and never ACCEPTS anything:
     * its true arm is unreachable by design, and the tri-state shape
     * keeps that law honest rather than flattening it into a bool that
     * reads as if affirmation were possible.
     */
    public static function secFetchBelt(?string $site): ?bool
    {
        if ($site !== null && strtolower(trim($site)) === 'cross-site') {
            return false;
        }

        return null;
    }

    /**
     * STRICT same-site law for cookie-authenticated state-changing
     * requests (CP-009/CP-012, §2.5) — both roots verbatim-identical:
     * belt first; neither Origin nor Referer → refuse (fail-closed);
     * literal `null` Origin → refuse (M-SERVER-76 opaque-origin marker);
     * every PRESENT header leg must name the Host RAW (case-sensitive —
     * the fail-closed direction) with both-or-neither ports and NO
     * default-port normalization ("host:80" never impersonates bare
     * "host", "evil-hub" never matches "hub"). An unparseable value is
     * refused outright; an unparseable Host falls back to the raw string
     * (matches nothing a browser sends: fail-CLOSED by construction).
     */
    public static function stateChangingRequestOriginAllowed(
        ?string $origin,
        ?string $referer,
        ?string $host,
        ?string $secFetchSite
    ): bool {
        // (1) CP-009 belt (hub arm order mirrored exactly: belt first).
        if (self::secFetchBelt($secFetchSite) === false) {
            return false;
        }

        // (2) Neither header present — refuse, fail-closed (ratified server
        // law; native clients ride the bearer carrier, never this one).
        if ($origin === null && $referer === null) {
            return false;
        }

        // (3) M-SERVER-76 (W-min109): a literal 'null' Origin is the
        // opaque-origin marker (sandboxed iframes and a few privacy
        // contexts) — a state-changing cookie-authenticated request that
        // cannot name its origin is refused outright.
        if ($origin === 'null') {
            return false;
        }

        // (4) Server host parts (unparseable Host falls back to the raw
        // value as the host — fail-CLOSED, see the method law).
        $serverHostParts = self::stateChangingHostParts($host ?? '');

        // (5) Referer arm: an unparseable Referer is refused outright, then
        // the exact-host + both-or-neither-port law applies.
        if ($referer !== null) {
            $refererParts = parse_url($referer);
            if (!is_array($refererParts)) {
                return false;
            }
            $refererHost = $refererParts['host'] ?? '';
            $refererPort = $refererParts['port'] ?? null;

            if (!self::stateChangingHostsMatch($refererHost, $refererPort, $serverHostParts)) {
                return false;
            }
        }

        // (6) Origin arm ('null' refused above; the empty string keeps its
        // skip — M-SERVER-76 names only the literal 'null').
        if ($origin !== null && $origin !== '') {
            $originParts = parse_url($origin);
            if (!is_array($originParts)) {
                return false;
            }
            $originHost = $originParts['host'] ?? '';
            $originPort = $originParts['port'] ?? null;

            if (!self::stateChangingHostsMatch($originHost, $originPort, $serverHostParts)) {
                return false;
            }
        }

        // (7) Every present arm agreed.
        return true;
    }

    /**
     * §2.1 device-class discriminator over raw header values (both roots'
     * law, server superset form — the hub delegates with
     * `$formEraBrowser = false`, reproducing its retired-SSR expression
     * exactly). Device marker present → bearer. An Origin that is present
     * but not a same-site http(s) origin → bearer. Otherwise a form-era
     * signal or `Sec-Fetch-Site: same-origin` marks the request
     * browser-class; with no signal at all the request stays bearer
     * (today's JSON posture for every legacy native client — byte-ident).
     *
     * Custody is the CLASSIFICATION axis only — the CP-009 belt does not
     * feed it (cross-site origins classify by their header arms here and
     * get refused later by the request law; both parents agree).
     *
     * @param bool        $formEraBrowser    Server-only signal (isBrowserRequest()).
     * @param string|null $deviceTypeHeader  Raw X-Phlix-Device-Type value.
     * @param string|null $originHeader      Raw Origin value.
     * @param string|null $hostHeader        Raw Host value.
     * @param string|null $secFetchSite      Raw Sec-Fetch-Site value.
     */
    public static function custodyMode(
        bool $formEraBrowser,
        ?string $deviceTypeHeader,
        ?string $originHeader,
        ?string $hostHeader,
        ?string $secFetchSite,
    ): string {
        if (is_string($deviceTypeHeader) && $deviceTypeHeader !== '') {
            return self::MODE_BEARER;
        }

        if (is_string($originHeader) && $originHeader !== '' && !self::originIsSameSite($originHeader, $hostHeader)) {
            return self::MODE_BEARER;
        }

        $browserClass = $formEraBrowser
            || (is_string($originHeader) && $originHeader !== '')
            || (is_string($secFetchSite) && strtolower($secFetchSite) === 'same-origin');

        return $browserClass ? self::MODE_COOKIE : self::MODE_BEARER;
    }

    /** Default port used when a side omits the :port (URL normalization). */
    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    /**
     * Lowercased hostname of a Host header value, :port stripped and bracketed
     * IPv6 literals unwrapped (parse_url keeps the brackets on both forms).
     */
    private static function hostOf(string $hostHeader): string
    {
        $parts = parse_url('//' . $hostHeader);
        $host = strtolower(is_array($parts) ? ($parts['host'] ?? '') : '');

        return str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
    }

    /**
     * Parse a Host header into host/port for the state-changing law.
     *
     * @return array{host: string, port: int|null}
     */
    private static function stateChangingHostParts(string $hostHeader): array
    {
        $parts = parse_url('http://' . $hostHeader);
        if (!is_array($parts)) {
            return ['host' => $hostHeader, 'port' => null];
        }

        return [
            'host' => $parts['host'] ?? $hostHeader,
            'port' => $parts['port'] ?? null,
        ];
    }

    /**
     * Byte-exact host equality plus the both-or-neither port rule — no
     * suffix matching ("evil-example.com" never matches "example.com") and no
     * default-port normalization ("host:80" never impersonates bare "host").
     *
     * @param array{host: string, port: int|null} $serverHostParts
     */
    private static function stateChangingHostsMatch(
        string $originHost,
        ?int $originPort,
        array $serverHostParts
    ): bool {
        if ($originHost !== $serverHostParts['host']) {
            return false;
        }

        $originHasPort = $originPort !== null;
        $serverHasPort = $serverHostParts['port'] !== null;

        if ($originHasPort !== $serverHasPort) {
            return false;
        }

        return !$originHasPort || $originPort === $serverHostParts['port'];
    }

    /**
     * Static-only kernel — no instances.
     */
    private function __construct()
    {
    }
}
