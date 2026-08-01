<?php

/**
 * Curl Arr Transport Validation Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Arr\Transport;

use PHPUnit\Framework\TestCase;
use Phlix\Shared\Arr\Transport\CurlArrTransport;
use RuntimeException;

/**
 * Unit tests for CurlArrTransport input validation.
 *
 * Tests the validation branches in request() for empty URL and empty method.
 *
 * @package Phlix\Shared\Tests\Arr\Transport
 */
final class CurlArrTransportValidationTest extends TestCase
{
    public function testRequestThrowsExceptionForEmptyUrl(): void
    {
        $transport = new CurlArrTransport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty request URL');

        $transport->request('GET', '', [], null);
    }

    public function testRequestThrowsExceptionForEmptyMethod(): void
    {
        $transport = new CurlArrTransport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty request method');

        $transport->request('', 'http://example.com/api', [], null);
    }

    public function testRequestWithValidUrlAndMethodDoesNotThrowOnValidation(): void
    {
        $transport = new CurlArrTransport(1, 1);

        // Using a quickly failing URL (0.0.0.0:1 is unroutable)
        // The validation should pass but the request itself will fail
        // This test just verifies we get past validation and hit the network
        try {
            $transport->request('GET', 'http://0.0.0.0:1/test', [], null);
            $this->fail('Expected RuntimeException from cURL');
        } catch (RuntimeException $e) {
            // We expect a cURL error because 0.0.0.0 is unroutable
            // But NOT the "empty request URL" or "empty request method" error
            $this->assertStringNotContainsString('empty request', $e->getMessage());
            $this->assertStringContainsString('cURL error', $e->getMessage());
        }
    }

    public function testRequestPostMethodWithEmptyBody(): void
    {
        $transport = new CurlArrTransport(1, 1);

        try {
            $transport->request('POST', 'http://0.0.0.0:1/api', ['Content-Type: application/json'], null);
            $this->fail('Expected RuntimeException from cURL');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('empty request', $e->getMessage());
        }
    }

    public function testRequestPutMethodWithBody(): void
    {
        $transport = new CurlArrTransport(1, 1);

        try {
            $transport->request('PUT', 'http://0.0.0.0:1/api', ['Content-Type: application/json'], '{"key":"value"}');
            $this->fail('Expected RuntimeException from cURL');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('empty request', $e->getMessage());
        }
    }

    public function testRequestDeleteMethod(): void
    {
        $transport = new CurlArrTransport(1, 1);

        try {
            $transport->request('DELETE', 'http://0.0.0.0:1/api/123', [], null);
            $this->fail('Expected RuntimeException from cURL');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('empty request', $e->getMessage());
        }
    }

    public function testRequestWithCustomMethod(): void
    {
        $transport = new CurlArrTransport(1, 1);

        try {
            $transport->request('PATCH', 'http://0.0.0.0:1/api', ['Content-Type: application/json'], '{"op":"replace","path":"/name","value":"new"}');
            $this->fail('Expected RuntimeException from cURL');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('empty request', $e->getMessage());
        }
    }
}
