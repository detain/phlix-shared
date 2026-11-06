<?php

/**
 * Secret Redactor Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Arr;

use PHPUnit\Framework\TestCase;
use Phlix\Shared\Arr\SecretRedactor;

/**
 * Unit tests for SecretRedactor.
 *
 * @package Phlix\Shared\Tests\Arr
 * @since 0.11.0
 */
class SecretRedactorTest extends TestCase
{
    public function testRedactReplacesSingleSecret(): void
    {
        $message = 'Connection failed: API key abc123secret was rejected';
        $result = SecretRedactor::redact($message, 'abc123secret');

        $this->assertSame('Connection failed: API key *** was rejected', $result);
    }

    public function testRedactReplacesMultipleSecrets(): void
    {
        $message = 'Failed to connect to http://api.example.com with key secret1 and key secret2';
        $result = SecretRedactor::redact($message, 'secret1', 'secret2');

        $this->assertSame('Failed to connect to http://api.example.com with key *** and key ***', $result);
    }

    public function testRedactHandlesApiKeyInUrl(): void
    {
        $message = 'GET http://localhost:7878/api/v3/movie?apiKey=my-super-secret-key';
        $result = SecretRedactor::redact($message, 'my-super-secret-key');

        $this->assertSame('GET http://localhost:7878/api/v3/movie?apiKey=***', $result);
    }

    public function testRedactHandlesEmptySecrets(): void
    {
        $message = 'Error: something went wrong';
        $result = SecretRedactor::redact($message, '', 'secret');

        $this->assertSame('Error: something went wrong', $result);
    }

    public function testRedactHandlesNoSecrets(): void
    {
        $message = 'Error: connection refused';
        $result = SecretRedactor::redact($message);

        $this->assertSame('Error: connection refused', $result);
    }

    public function testRedactHandlesSecretNotPresent(): void
    {
        $message = 'Error: connection refused';
        $result = SecretRedactor::redact($message, 'nonexistent');

        $this->assertSame('Error: connection refused', $result);
    }

    public function testRedactHandlesCaseSensitivity(): void
    {
        $message = 'API Key: SecretKey and secretkey are different';
        $result = SecretRedactor::redact($message, 'SecretKey');

        $this->assertSame('API Key: *** and secretkey are different', $result);
    }

    /**
     * LW-055 [review shared__srphp Minor #4]: overlapping secrets must be
     * replaced longest-first. Red-first at the parent commit: str_replace
     * applied the secrets in argument order, so the short 'KEY' pass ran
     * before 'SECRETKEY' and stranded the 'SECRET' residue of the longer
     * secret in the message.
     */
    public function testRedactOrdersOverlappingSecretsLongestFirst(): void
    {
        $message = 'Error with key SECRETKEY and token abc123secret for KEY';
        $result = SecretRedactor::redact($message, 'abc123secret', 'KEY', 'SECRETKEY');

        $this->assertSame('Error with key *** and token *** for ***', $result);
    }

    /**
     * LW-055: nested secret — the shorter secret is a strict prefix of the
     * longer one, so argument order alone would shred the longer value.
     */
    public function testRedactLongestWinsWhenSecretsAreNested(): void
    {
        $message = 'url=/feed?apiKey=AAABBBCCC&alt=AAABBB';
        $result = SecretRedactor::redact($message, 'AAABBB', 'AAABBBCCC');

        $this->assertSame('url=/feed?apiKey=***&alt=***', $result);
    }

    /**
     * LW-055: equal-length secrets keep working (PHP 8 sorts are stable —
     * equal lengths preserve the caller's order; either replacement is a
     * single '***' so the outcome is order-independent anyway).
     */
    public function testRedactHandlesEqualLengthSecrets(): void
    {
        $message = 'keys alpha and bravo both leak';
        $result = SecretRedactor::redact($message, 'alpha', 'bravo');

        $this->assertSame('keys *** and *** both leak', $result);
    }
}
