<?php

/**
 * Security: CredentialDigest unit suite.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Security;

use Phlix\Shared\Security\CredentialDigest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The digest law is wire law (D-13 columns, B-16/B-19 SPEC-pinned join
 * password): these vectors are frozen — a changed output byte is a
 * mass-invalidating event, not a refactor.
 */
#[CoversClass(CredentialDigest::class)]
final class CredentialDigestTest extends TestCase
{
    public function test_digest_of_empty_string_is_the_frozen_sha256_vector(): void
    {
        self::assertSame(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            CredentialDigest::digest(''),
        );
    }

    public function test_digest_matches_the_parents_wire_form(): void
    {
        // Byte-for-byte the parents' shape: hash('sha256', $value), lowercase
        // hex, 64 chars — the form stored in oauth_clients_secret,
        // mcp_tokens_token, server_invites_token and the SPEC-pinned
        // sha256(group_join_password) SyncPlay handshake field.
        $digest = CredentialDigest::digest('s3cret-token-value');

        self::assertSame(hash('sha256', 's3cret-token-value'), $digest);
        self::assertSame(64, strlen($digest));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $digest);
    }

    public function test_match_accepts_the_digesting_secret(): void
    {
        $secret = 'aHR0cHM6Ly9leGFtcGxlLmNvbQ';
        self::assertTrue(CredentialDigest::match($secret, CredentialDigest::digest($secret)));
    }

    public function test_match_refuses_every_mismatch_shape(): void
    {
        $stored = CredentialDigest::digest('right-secret');

        // Wrong secret.
        self::assertFalse(CredentialDigest::match('wrong-secret', $stored));
        // Empty presented against a real digest.
        self::assertFalse(CredentialDigest::match('', $stored));
        // Case-mangled stored form (the column is lowercase hex; a mangled
        // row can only come from corruption, and refusing is the honest read).
        self::assertFalse(CredentialDigest::match('right-secret', strtoupper($stored)));
        // Truncated stored form (legacy/damaged row — never a substring match).
        self::assertFalse(CredentialDigest::match('right-secret', substr($stored, 0, 63)));
        // Empty stored form.
        self::assertFalse(CredentialDigest::match('anything', ''));
    }

    public function test_digest_is_deterministic_across_calls(): void
    {
        self::assertSame(
            CredentialDigest::digest('group-join-password'),
            CredentialDigest::digest('group-join-password'),
        );
    }
}
