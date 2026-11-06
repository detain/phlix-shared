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
 * status: this class has NO production importer today — phlix-server signs
 * webhooks with a raw `hash_hmac()` call, and its plugin-manifest checking
 * is a trusted-digest allowlist, not a keyed HMAC verification. It exists
 * as the single-source, timing-safe implementation of the `sha256:<hex>`
 * convention so a future keyed consumer (relay token signing is the
 * canonical candidate) adopts one audited primitive instead of
 * hand-rolling a comparison. The exposed surface is `compute()` and
 * `verify()` — there is no `sign()` method.
 *
 * Single contract: {@see self::verify()} is the canonical entry point and
 * {@see self::verifySha256Hex()} is a deprecated alias delegating to it
 * verbatim — one comparison, no duplicated logic that could diverge.
 *
 * @package Phlix\Shared\Security
 * @since 0.10.0
 */
final class Hmac
{
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
     * `hash_equals` over the full `sha256:`-prefixed string. A wrong prefix or
     * length fails inside `hash_equals` — no pre-check can add safety here,
     * and earlier ones were decoration.
     *
     * @param string $payload The data that was signed.
     * @param string $signature The signature to verify (expecting `sha256:<hex>` format).
     * @param string $secret The shared secret key.
     *
     * @return bool True when the signature is valid, false otherwise.
     *
     * @since 0.10.0
     */
    public static function verify(string $payload, string $signature, string $secret): bool
    {
        $expected = self::compute($payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Compute the HMAC-SHA256 signature for a payload.
     *
     * Returns the signature in `sha256:<hex>` colon-hex format — the format
     * convention this class implements (see the class header for honest
     * consumer status).
     *
     * @param string $payload The data to sign.
     * @param string $secret The shared secret key.
     *
     * @return string The computed signature in `sha256:<hex>` format.
     *
     * @since 0.10.0
     */
    public static function compute(string $payload, string $secret): string
    {
        $hash = hash_hmac('sha256', $payload, $secret, true);

        return 'sha256:' . bin2hex($hash);
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
     * @param string $signature The signature in `sha256:<hex>` format.
     * @param string $secret The shared secret key.
     *
     * @return bool True when the signature is valid, false otherwise.
     *
     * @since 0.10.0
     */
    public static function verifySha256Hex(string $payload, string $signature, string $secret): bool
    {
        return self::verify($payload, $signature, $secret);
    }
}
