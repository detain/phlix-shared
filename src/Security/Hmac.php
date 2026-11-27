<?php

/**
 * Hmac.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Security;

/**
 * HMAC-SHA256 signature computation and verification helper.
 *
 * Timing-safe `sha256:<hex>` (colon-hex) keyed-HMAC seam. Honest consumer
 * status: phlix-server webhooks import this class today — the legacy
 * `sha256=<hex>` header arms route through `compute()`/`verify()` with
 * `SEPARATOR_EQUALS` (`WebhookEvent::getSignature()` signs,
 * `WebhookEvent::verify()` accepts the legacy arm). Not yet migrated: the
 * timestamped V2 (`t=<unix>,v1=<hex>`) sign/verify pair, which still calls
 * raw `hash_hmac()`, and plugin-manifest checking, which is a
 * trusted-digest allowlist, not a keyed HMAC verification. The class
 * remains the single-source, timing-safe implementation of the
 * `sha256:<hex>` convention for future keyed consumers (relay token signing
 * is the canonical candidate). The exposed surface is `compute()` and
 * `verify()` — there is no `sign()` method.
 *
 * Single contract: {@see self::verify()} is the canonical entry point and
 * {@see self::verifySha256Hex()} is a deprecated alias delegating to it
 * verbatim — one comparison, no duplicated logic that could diverge.
 *
 * Envelope separator (v0.56.0). The class documented exactly one
 * envelope — `sha256:<hex>` — while the estate has a second, wire-frozen
 * sibling: the webhook legacy header is `sha256=<hex>` and receivers gate on
 * that literal. The separator is now a trailing parameter defaulting to the
 * colon, so the documented `sha256:` contract above stays byte-true for every
 * existing call shape, and the equals arm lets the webhook signer/verifier
 * adoption in phlix-server (`src/Webhooks/WebhookEvent.php`, red-first
 * byte-pinned against recorded `sha256=` fixtures there) reuse this one
 * audited primitive instead of hand-rolling the envelope — the "false-SSOT
 * with zero importers is itself the defect" debt paid forward.
 *
 * @package Phlix\Shared\Security
 * @since 0.10.0
 */
final class Hmac
{
    /** Colon envelope — the original documented convention (`sha256:<hex>`). */
    public const string SEPARATOR_COLON = ':';

    /** Equals envelope — the webhook legacy wire literal (`sha256=<hex>`). */
    public const string SEPARATOR_EQUALS = '=';

    /**
     * Prevent instantiation — this class is a static utility only.
     */
    private function __construct()
    {
    }

    /**
     * Verify an HMAC-SHA256 signature against a payload.
     *
     * The canonical contract of this class: compares the provided signature
     * with a computed HMAC-SHA256 over the payload using one timing-safe
     * `hash_equals` over the full prefixed string. A wrong prefix or
     * length fails inside `hash_equals` — no pre-check can add safety here,
     * and earlier ones were decoration.
     *
     * @param string $payload The data that was signed.
     * @param string $signature The signature to verify (expecting `sha256<separator><hex>` format).
     * @param string $secret The shared secret key.
     * @param string $separator The envelope separator; defaults to the historical colon.
     *
     * @return bool True when the signature is valid, false otherwise.
     *
     * @since 0.10.0
     */
    public static function verify(
        string $payload,
        string $signature,
        string $secret,
        string $separator = self::SEPARATOR_COLON
    ): bool {
        $expected = self::compute($payload, $secret, $separator);

        return hash_equals($expected, $signature);
    }

    /**
     * Compute the HMAC-SHA256 signature for a payload.
     *
     * Returns the signature in `sha256<separator><hex>` format — with the
     * default colon this is the `sha256:<hex>` convention this class was
     * built for (see the class header for honest consumer status); the
     * equals arm reproduces the webhook legacy envelope byte-for-byte.
     *
     * @param string $payload The data to sign.
     * @param string $secret The shared secret key.
     * @param string $separator The envelope separator; defaults to the historical colon.
     *
     * @return string The computed signature in `sha256<separator><hex>` format.
     *
     * @since 0.10.0
     */
    public static function compute(string $payload, string $secret, string $separator = self::SEPARATOR_COLON): string
    {
        $hash = hash_hmac('sha256', $payload, $secret, true);

        return 'sha256' . $separator . bin2hex($hash);
    }

    /**
     * Verify an HMAC-SHA256 signature in `sha256:<hex>` format.
     *
     * @deprecated Use {@see self::verify()} — the single canonical contract of
     *             this class. This alias existed as a duplicate implementation
     *             of the exact same comparison (its prefix pre-check was
     *             decoration: `hash_equals` already rejects a wrong prefix);
     *             it is now a verbatim delegation, kept for source
     *             compatibility, and emits no runtime notice.
     *
     * @param string $payload The data that was signed.
     * @param string $signature The signature in `sha256<separator><hex>` format.
     * @param string $secret The shared secret key.
     * @param string $separator The envelope separator; defaults to the historical colon.
     *
     * @return bool True when the signature is valid, false otherwise.
     *
     * @since 0.10.0
     */
    public static function verifySha256Hex(
        string $payload,
        string $signature,
        string $secret,
        string $separator = self::SEPARATOR_COLON
    ): bool {
        return self::verify($payload, $signature, $secret, $separator);
    }
}
