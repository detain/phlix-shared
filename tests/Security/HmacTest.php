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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Phlix\Shared\Security\Hmac::class)]
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

    /**
     * M-SHARED-26: the pre-existing suite was 100% round-trip (compute→verify),
     * so a wrong-but-stable implementation stayed green. This pinned
     * known-answer vector is the independent anchor: it is computed with the
     * reference `hash_hmac` recipe and pins the exact literal output.
     */
    public function testComputeMatchesThePinnedKnownAnswerVector(): void
    {
        $this->assertSame(
            'sha256:b82fcb791acec57859b989b430a826488ce2e479fdf92326bd0a2e8375a42ba4',
            Hmac::compute('payload', 'secret'),
        );
    }

    /**
     * M-SHARED-26: `verify()` is the canonical contract and the deprecated
     * `verifySha256Hex()` is now a delegation to it. This pin keeps the two
     * entry points answering identically on the shapes the old duplicated
     * implementation handled specially (wrong prefix, foreign algorithm,
     * truncated hex), so the delegation can never silently diverge again.
     */
    public function testBothEntryPointsAnswerIdenticallyForEverySignatureShape(): void
    {
        $payload = 'round-trip payload';
        $secret = 'topsecret';
        $valid = Hmac::compute($payload, $secret);

        $shapes = [
            'valid' => $valid,
            'wrong-prefix' => str_replace('sha256:', 'sha512:', $valid),
            'foreign-algorithm-label' => 'sha1:abc123',
            'truncated-hex' => substr($valid, 0, 20),
            'empty' => '',
            'correct-prefix-wrong-hex' => 'sha256:' . str_repeat('00', 32),
        ];

        foreach ($shapes as $label => $signature) {
            $this->assertSame(
                Hmac::verify($payload, $signature, $secret),
                Hmac::verifySha256Hex($payload, $signature, $secret),
                sprintf('Entry points diverged for shape "%s".', $label),
            );
        }
    }

    public function testComputeDefaultSeparatorBytesAreUnchanged(): void
    {
        // BC law: the colon envelope this class documented since 0.10.0 must
        // be the default-arm output to the byte, after the separator seam.
        $this->assertSame(
            'sha256:' . hash_hmac('sha256', 'payload', 'secret'),
            Hmac::compute('payload', 'secret')
        );
        $this->assertSame(
            'sha256:b82fcb791acec57859b989b430a826488ce2e479fdf92326bd0a2e8375a42ba4',
            Hmac::compute('payload', 'secret')
        );
        $this->assertTrue(Hmac::verify('payload', Hmac::compute('payload', 'secret'), 'secret'));
    }

    public function testComputeEqualsSeparatorProducesTheWebhookLegacyEnvelope(): void
    {
        // G-03: the webhook wire's legacy header is exactly `sha256=<hex>`
        // (receiver gates on the literal). The equals arm must reproduce it
        // byte-identically so the S1.10 adoption can delegate the envelope.
        $this->assertSame(
            'sha256=' . hash_hmac('sha256', 'payload', 'secret'),
            Hmac::compute('payload', 'secret', Hmac::SEPARATOR_EQUALS)
        );
        $this->assertSame(
            'sha256=b82fcb791acec57859b989b430a826488ce2e479fdf92326bd0a2e8375a42ba4',
            Hmac::compute('payload', 'secret', Hmac::SEPARATOR_EQUALS)
        );
    }

    public function testSeparatorConstantsCarryTheWireLiterals(): void
    {
        $this->assertSame(':', Hmac::SEPARATOR_COLON);
        $this->assertSame('=', Hmac::SEPARATOR_EQUALS);
    }

    public function testVerifyHonoursTheSeparatorAndCrossSeparationFails(): void
    {
        $equalsSig = Hmac::compute('payload', 'secret', Hmac::SEPARATOR_EQUALS);
        $colonSig = Hmac::compute('payload', 'secret');

        $this->assertTrue(Hmac::verify('payload', $equalsSig, 'secret', Hmac::SEPARATOR_EQUALS));
        $this->assertTrue(Hmac::verify('payload', $colonSig, 'secret'));

        // A signature computed under one envelope never verifies under the
        // other — the separator is part of the compared string, and
        // hash_equals does the whole job (no pre-checks to add).
        $this->assertFalse(Hmac::verify('payload', $equalsSig, 'secret'));
        $this->assertFalse(Hmac::verify('payload', $colonSig, 'secret', Hmac::SEPARATOR_EQUALS));
    }

    public function testDeprecatedAliasStillDelegatesUnderBothSeparators(): void
    {
        foreach ([Hmac::SEPARATOR_COLON, Hmac::SEPARATOR_EQUALS] as $separator) {
            $signature = Hmac::compute('payload', 'secret', $separator);

            $this->assertSame(
                Hmac::verify('payload', $signature, 'secret', $separator),
                Hmac::verifySha256Hex('payload', $signature, 'secret', $separator)
            );
        }
    }
}
