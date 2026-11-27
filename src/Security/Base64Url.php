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
 * genuinely diverged, and that divergence is preserved HERE as explicit
 * arms instead of being silently unified:
 *
 *  - `decode($encoded)` — the STRICT arm by default since S-shared-07: the
 *    body of `HubJwtValidator::base64UrlDecode()` and
 *    `JwtHandler::base64UrlDecode()` (`base64_decode(..., true) ?: ''` / the
 *    `(string)` cast) — out-of-alphabet characters collapse the whole input
 *    to `''`, never a partial decode.
 *  - `decode($encoded, false)` — the LAX arm as an explicit opt-in: the body
 *    of the legacy `Auth/JwtHandler::base64UrlDecode()`
 *    (`base64_decode(strtr(...))` without the strict flag) — out-of-alphabet
 *    characters are silently stripped and what remains is decoded. It was
 *    the DEFAULT until S-shared-07, and that lax default is what
 *    phlix-shared-007 convicted: every JWT segment decoded through it was
 *    malleable.
 *  - `decodeJwtSegment($encoded)` — the canonical JWT-segment arm: strict
 *    plus an alphabet/length pre-check, so equivalent-but-non-canonical
 *    encodings (`+`, `/`, `=` padding, whitespace) never decode at all.
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
     * is the acceptance each historical call site had. JWT segments need a
     * stricter posture than either arm offers; that is
     * {@see self::decodeJwtSegment()}.
     *
     * @param string $encoded The base64url-encoded input.
     * @param bool   $strict  True (the default since S-shared-07) selects the
     *                        STRICT arm (malformed input → `''`); false is an
     *                        explicit opt-in to the LAX arm (out-of-alphabet
     *                        characters are stripped), retained so the legacy
     *                        server Auth/JwtHandler body migrates byte-neutral.
     *
     * @return string The decoded bytes; `''` on any failure. Never `false`.
     */
    public static function decode(string $encoded, bool $strict = true): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), $strict);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Decode a CANONICAL JWT segment: unpadded base64url, nothing else.
     *
     * Base64 is a many-to-one encoding and both arms of {@see self::decode()}
     * inherit the ambiguity: strict mode still accepts `+`, `/`, `=` padding
     * and even embedded whitespace, so byte-distinct segment strings that mean
     * the same thing verify identically — the token-malleability surface
     * phlix-shared-007 convicted. A conformant base64url producer emits only
     * `[A-Za-z0-9_-]` at a length that is never `1 mod 4`, so this arm refuses
     * everything else BEFORE the kernel decode. The `1 mod 4` length check is
     * a belt — PHP's strict layer rejects those inputs too (probed at writing);
     * the alphabet guard is load-bearing: whitespace is the non-alphabet class
     * strict mode lets through, and `+`/`/`/`=` are the alphabet-and-padding
     * variants strict mode accepts.
     *
     * Residual leniency, documented honestly: strict PHP does not verify that
     * the unused trailing bits of a `2 mod 4`/`3 mod 4` final group are zero,
     * so ragged-tail bit variants still pass the kernel — but they decode to
     * the SAME bytes, so the residual ambiguity is invisible downstream and
     * re-opens nothing a verifier could observe.
     *
     * Like the rest of the class the return is `''` on refusal, never `false`;
     * `''` doubles as the legitimate empty-segment verdict (the
     * {@see self::decodePadded()} precedent), so callers treat refusal exactly
     * as the historical `(string)`-cast consumers did.
     *
     * @param string $encoded The candidate segment, as it appears in the token.
     *
     * @return string The decoded bytes; `''` on any refusal. Never `false`.
     *
     * @since S-shared-07 (phlix-shared-007 — canonical segment arm; ships in
     *        the next coordinated release)
     */
    public static function decodeJwtSegment(string $encoded): string
    {
        if (preg_match('~^[A-Za-z0-9_-]*$~D', $encoded) !== 1 || strlen($encoded) % 4 === 1) {
            return '';
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

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
     * Contrast with {@see self::decode()}: there no padding is invented and
     * the default arm is strict (the LAX arm is an explicit opt-in since
     * S-shared-07). This arm always
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
