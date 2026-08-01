<?php

/**
 * Hmac Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Security;

use Phlix\Shared\Security\Hmac;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Security\Hmac
 */
final class HmacTest extends TestCase
{
    public function testComputeReturnsSha256Prefix(): void
    {
        $signature = Hmac::compute('payload', 'secret');

        $this->assertStringStartsWith('sha256:', $signature);
    }

    public function testComputeIsDeterministic(): void
    {
        $sig1 = Hmac::compute('payload', 'secret');
        $sig2 = Hmac::compute('payload', 'secret');

        $this->assertSame($sig1, $sig2);
    }

    public function testComputeDifferentPayloadsProduceDifferentSignatures(): void
    {
        $sig1 = Hmac::compute('payload1', 'secret');
        $sig2 = Hmac::compute('payload2', 'secret');

        $this->assertNotSame($sig1, $sig2);
    }

    public function testComputeDifferentSecretsProduceDifferentSignatures(): void
    {
        $sig1 = Hmac::compute('payload', 'secret1');
        $sig2 = Hmac::compute('payload', 'secret2');

        $this->assertNotSame($sig1, $sig2);
    }

    public function testVerifyReturnsTrueForValidSignature(): void
    {
        $payload = 'test payload';
        $secret = 'my-secret-key';
        $signature = Hmac::compute($payload, $secret);

        $this->assertTrue(Hmac::verify($payload, $signature, $secret));
    }

    public function testVerifyReturnsFalseForInvalidSignature(): void
    {
        $payload = 'test payload';
        $secret = 'my-secret-key';

        $this->assertFalse(Hmac::verify($payload, 'sha256:invalid', $secret));
    }

    public function testVerifyReturnsFalseForWrongSecret(): void
    {
        $payload = 'test payload';
        $signature = Hmac::compute($payload, 'secret1');

        $this->assertFalse(Hmac::verify($payload, $signature, 'secret2'));
    }

    public function testVerifyReturnsFalseForTamperedPayload(): void
    {
        $secret = 'my-secret-key';
        $signature = Hmac::compute('original payload', $secret);

        $this->assertFalse(Hmac::verify('tampered payload', $signature, $secret));
    }

    public function testVerifySha256HexReturnsTrueForValidPrefixFormat(): void
    {
        $payload = 'test payload';
        $secret = 'my-secret-key';
        $signature = Hmac::compute($payload, $secret);

        $this->assertTrue(Hmac::verifySha256Hex($payload, $signature, $secret));
    }

    public function testVerifySha256HexReturnsFalseForInvalidPrefix(): void
    {
        $payload = 'test payload';
        $secret = 'my-secret-key';

        // Create a signature with wrong prefix
        $wrongPrefixSignature = 'md5:' . substr(Hmac::compute($payload, $secret), 7);

        $this->assertFalse(Hmac::verifySha256Hex($payload, $wrongPrefixSignature, $secret));
    }

    public function testVerifySha256HexReturnsFalseForNonSha256Prefix(): void
    {
        $payload = 'test payload';
        $secret = 'my-secret-key';

        $this->assertFalse(Hmac::verifySha256Hex($payload, 'sha1:abc123', $secret));
    }

    public function testVerifySha256HexReturnsFalseForInvalidSignature(): void
    {
        $payload = 'test payload';
        $secret = 'my-secret-key';

        $this->assertFalse(Hmac::verifySha256Hex($payload, 'sha256:invalid0000000000000000000000000000000', $secret));
    }

    public function testVerifySha256HexReturnsFalseForWrongSecret(): void
    {
        $payload = 'test payload';
        $signature = Hmac::compute($payload, 'secret1');

        $this->assertFalse(Hmac::verifySha256Hex($payload, $signature, 'secret2'));
    }

    public function testComputeProducesValidHexAfterPrefix(): void
    {
        $signature = Hmac::compute('payload', 'secret');

        $hexPart = substr($signature, 7);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hexPart);
    }

    public function testEmptyPayloadCanBeSignedAndVerified(): void
    {
        $payload = '';
        $secret = 'secret';

        $signature = Hmac::compute($payload, $secret);

        $this->assertTrue(Hmac::verify($payload, $signature, $secret));
        $this->assertTrue(Hmac::verifySha256Hex($payload, $signature, $secret));
    }

    public function testEmptySecretCanBeUsed(): void
    {
        $payload = 'test payload';
        $secret = '';

        $signature = Hmac::compute($payload, $secret);

        $this->assertTrue(Hmac::verify($payload, $signature, $secret));
        $this->assertTrue(Hmac::verifySha256Hex($payload, $signature, $secret));
    }

    public function testVerifyWithEmptyStrings(): void
    {
        $payload = 'test';
        $secret = '';

        $signature = Hmac::compute($payload, $secret);

        $this->assertFalse(Hmac::verify($payload, $signature, 'wrong'));
        $this->assertFalse(Hmac::verifySha256Hex($payload, $signature, 'wrong'));
    }
}
