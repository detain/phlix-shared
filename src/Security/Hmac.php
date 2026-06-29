<?php

declare(strict_types=1);

namespace Phlix\Shared\Security;

/**
 * HMAC-SHA256 signature verification helper.
 *
 * Provides timing-safe comparison for verifying HMAC-SHA256 signatures
 * in the `sha256:<hex>` format used by Phlix manifest files.
 *
 * @package Phlix\Shared\Security
 * @since 0.10.0
 */
final class Hmac
{
    /**
     * Verify an HMAC-SHA256 signature against a payload.
     *
     * Compares the provided signature with a computed HMAC-SHA256 over the
     * payload using timing-safe string comparison to prevent timing attacks.
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
     * Returns the signature in `sha256:<hex>` format matching the manifest
     * signature field format.
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
     * Convenience method that accepts the `sha256:<hex>` formatted signature
     * directly, extracting the hex portion for comparison.
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
        if (!str_starts_with($signature, 'sha256:')) {
            return false;
        }

        $expectedHash = 'sha256:' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedHash, $signature);
    }
}
