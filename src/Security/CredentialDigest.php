<?php

/**
 * Security: SHA-256 credential digests (the estate's hex-digest custody form).
 *
 * Single source for the digest law the estate repeats in at least four
 * places (phlix-server `AuthManager::hashToken()` / the OAuth2 client +
 * MCP token + invite-link digesters, all byte-identical `hash('sha256', …)`
 * hex; phlix-hub `OAuth\OAuthClient::verifySecret()` / invite digests).
 * The fold is DIGEST-SHAPE only: the wire form of every stored value is
 * unchanged — same input bytes, same output bytes, byte-ident rows in the
 * `oauth_clients_secret` / `mcp_tokens_token` / `server_invites_token`
 * columns (D-13 census) and the SPEC-pinned SyncPlay join-password wire
 * (B-16/B-19: `password_hash: sha256(password, group_join_password)` —
 * SPEC-locked, so this is the ONE form no migration may ever touch).
 *
 * What this is NOT: user passwords. Those live on argon2id via
 * {@see \Phlix\Shared\Auth\PasswordHasher}; a sha256 digest of a low-
 * entropy human password is rainbow-table fodder, and the estate already
 * carries that split ratification (phlix-server `tests/Unit/Auth/
 * PasswordHashMigrationTargetsTest`). Digest a secret only when the input
 * is high-entropy (tokens, generated secrets, spec-mandated wire digests).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Security;

/**
 * Pure SHA-256 hex digesting of high-entropy credential material.
 *
 * Charter fit: `hash()` is a pure in-process primitive — zero I/O, zero
 * ambient state, byte-deterministic output (the estate's fixture law, cf.
 * D-07: fixtures never embed runtime-random forms).
 *
 * @package Phlix\Shared\Security
 */
final class CredentialDigest
{
    /**
     * Hex SHA-256 of the secret — the exact form both parents store and
     * compare (`hash('sha256', $value)`), pinned by the estate's own
     * vectors (empty string → e3b0c442…b855).
     */
    public static function digest(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Compare a presented secret against a stored hex digest in constant
     * time. False for any mismatch, any non-hex or wrong-length stored
     * form (it simply never equals the computed digest — the parents'
     * shape: they never re-validate the stored column's syntax at read).
     */
    public static function match(string $secret, string $storedHex): bool
    {
        return hash_equals($storedHex, self::digest($secret));
    }

    /**
     * Static-only kernel — no instances.
     */
    private function __construct()
    {
    }
}
