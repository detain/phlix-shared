<?php

/**
 * Security Base64 Url.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Security;

/**
 * Base64url (RFC 4648 §5) codec — the single home of the encode/decode pair
 * the estate previously kept as eleven-plus private methods.
 *
 * ## Why the pair is safe to centralize
 *
 * Every `base64UrlEncode()` in phlix-server and phlix-hub computes the same
 * bijection — `rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=')` — so
 * adoption changes no emitted byte. The decode side is where families
 * genuinely diverged, and that divergence is preserved HERE as two explicit
 * arms instead of being silently unified:
 *
 *  - `decode($encoded, true)` — the STRICT arm, the body of
 *    `HubJwtValidator::base64UrlDecode()` and `JwtHandler::base64UrlDecode()`
 *    (`base64_decode(..., true) ?: ''` / the `(string)` cast): out-of-alphabet
 *    characters collapse the whole input to `''`, never a partial decode.
 *  - `decode($encoded)` — the LAX arm, the body of the legacy
 *    `Auth/JwtHandler::base64UrlDecode()` (`base64_decode(strtr(...))` without
 *    the strict flag): out-of-alphabet characters are silently stripped and
 *    what remains is decoded.
 *
 * Both arms share the `''`-on-failure coercion, and neither ever surfaces
 * `base64_decode()`'s implicit `false` — the return type is `string`, full
 * stop. A migrating call site names its arm in a red-first test BEFORE
 * re-pointing (DESIGN §M-01), which is what keeps the unification byte-neutral
 * per repo.
 *
 * @package Phlix\Shared\Security
 * @since   0.56.0 (S0 / M-01 — canonical-source: exact family, strictest arm
 *        documented per DESIGN §0)
 */
final class Base64Url
{
    /**
     * This class exposes only static operations.
     */
    private function __construct()
    {
    }

    /**
     * Encode raw bytes as unpadded base64url.
     *
     * `+` becomes `-`, `/` becomes `_`, and trailing `=` padding is removed —
     * exactly the pair of substitutions every historical call site performed.
     *
     * @param string $bytes Raw binary input.
     *
     * @return string The base64url representation (never contains `+`, `/` or `=`).
     */
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Decode a base64url string back to raw bytes.
     *
     * Padding, `+` and `/` in the input are accepted by both arms — the kernel
     * normalizes only `-`/`_` and defers validity to PHP's base64 layer, which
     * is the acceptance each historical call site had.
     *
     * @param string $encoded The base64url-encoded input.
     * @param bool   $strict  True selects the STRICT arm (malformed input →
     *                        `''`); false (default) selects the LAX arm
     *                        (out-of-alphabet characters are stripped).
     *
     * @return string The decoded bytes; `''` on any failure. Never `false`.
     */
    public static function decode(string $encoded, bool $strict = false): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), $strict);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Decode base64url that may be UNPADDED, with the strict legacy
     * WebAuthn consumer's posture (v0.59 sign-arm split's strict arm).
     *
     * The server's historical WebAuthn decoder (`base64UrlDecode`, the
     * ee9f1c4c family) normalizes `-`/`_`, then pads back to a multiple
     * of 4 with `=` BEFORE a STRICT decode: a ragged tail is refused
     * outright and can never sneak through as a stripped-alphabet
     * interpretation. This kernel method is that body verbatim, with
     * PHP's `false` mapped to `''` — this class' never-false law; a
     * caller that throws maps `''` exactly as the falsy verdict, because
     * the consumer has always treated `false` and `''` identically
     * (its guard is `=== false || === ''`), so the coercion is
     * observationally zero-delta.
     *
     * Contrast with {@see self::decode()}: there the LAX default strips
     * invalid alphabet and no padding is invented. This arm always
     * pads first and is always strict; the arms differ exactly on
     * inputs whose length is not a multiple of four (and on garbage).
     *
     * @param string $encoded The base64url-encoded input.
     *
     * @return string The decoded bytes; `''` on any failure, including
     *                empty input. Never `false`.
     */
    public static function decodePadded(string $encoded): string
    {
        $normalized = strtr($encoded, '-_', '+/');
        $padded = str_pad($normalized, (int) (ceil(strlen($normalized) / 4) * 4), '=');
        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }
}
