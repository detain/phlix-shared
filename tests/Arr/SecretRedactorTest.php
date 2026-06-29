<?php

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
}
