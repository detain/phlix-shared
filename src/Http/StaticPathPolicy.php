<?php

/**
 * StaticPathPolicy.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Http;

/**
 * The estate's one static-file deny vocabulary (v0.57 leg 0.10 M-18, A-21).
 *
 * Lifted from phlix-server `src/Server/Workerman/HttpHandler.php` —
 * `DENIED_STATIC_EXTENSIONS` (:107, R20-03) + the inlined strict predicate
 * (:717) — and phlix-hub `src/Application.php` — the typed const declared
 * "byte-coordinated with phlix-server ... so the estate has one deny
 * vocabulary on both HTTP daemons" (:154, 85c06de1) + the public-static
 * `isDeniedStaticPath()` (:176-183), whose pinnable shape is the API donor.
 *
 * Rationale per the twins' own docblocks: `php` = never hand out raw script
 * sources; `map` = Vite source maps carrying the SPA's full
 * `sourcesContent` (build artifacts for tooling, not client-facing);
 * `tpl` = Smarty email template sources (read server-side by path, never
 * over HTTP). Both hosts deny on the JAILED realpath so a case trick or
 * symlink cannot smuggle one back in; the request falls through to the
 * dispatcher exactly like the pre-existing php deny.
 *
 * Preserved observable behavior (both twins): `pathinfo()` reports a
 * dotless final segment as the extension, so a jailed path ending in a bare
 * denied word (`/public/php`) IS denied — intentional under the twins.
 *
 * Disclosed kernel deltas: the server inline applied an explicit
 * `(string)` cast to `pathinfo()` (defensive; a no-op on every real input
 * under PHP 8.3+), the hub omitted it — the shared `extensionOf()` keeps
 * the cast. Body-cap policy (B-15-adjacent) is NOT shared: per-repo caps
 * legitimately differ.
 */
final class StaticPathPolicy
{
    /**
     * Lowercase file extensions a static file server must never hand out.
     *
     * @var list<string>
     */
    public const array DENIED_STATIC_EXTENSIONS = ['php', 'map', 'tpl'];

    /**
     * Non-instantiable: static predicate kernel only.
     */
    private function __construct()
    {
    }

    /**
     * The path's final extension, verbatim case (empty string when absent).
     */
    public static function extensionOf(string $path): string
    {
        return (string) pathinfo($path, PATHINFO_EXTENSION);
    }

    /**
     * True when a resolved static path must not be served (R20-03).
     *
     * Case-insensitive per-entry strict membership — the twins' predicate:
     * `in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), DENIED, true)`.
     */
    public static function isDeniedStaticPath(string $path): bool
    {
        return in_array(
            strtolower(self::extensionOf($path)),
            self::DENIED_STATIC_EXTENSIONS,
            true,
        );
    }
}
