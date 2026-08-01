<?php

/**
 * Radarr Client CRUD Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Arr;

use PHPUnit\Framework\TestCase;
use Phlix\Shared\Arr\RadarrClient;
use Phlix\Shared\Arr\Transport\ArrTransportInterface;

/**
 * Unit tests for RadarrClient CRUD operations that use PUT and DELETE.
 *
 * @package Phlix\Tests\Unit\Arr
 */
class RadarrClientCrudTest extends TestCase
{
    /**
     * Mock transport that captures all HTTP calls for verification.
     */
    private CaptureTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new CaptureTransport();
    }

    public function testCreateCustomFormatSendsPostRequest(): void
    {
        $client = new RadarrClient(
            'http://localhost:7878',
            'test-api-key',
            null,
            30,
            $this->transport
        );

        $payload = [
            'name' => 'BR-Dish',
            'format' => 1,
            'score' => 100,
        ];

        $result = $client->createCustomFormat($payload);

        $this->assertSame(10, $result); // CaptureTransport returns id 10
        $this->assertCount(1, $this->transport->calls);
        $this->assertSame('POST', $this->transport->calls[0]['method']);
        $this->assertSame('http://localhost:7878/api/v3/customformat', $this->transport->calls[0]['url']);
    }

    public function testUpdateCustomFormatSendsPutRequest(): void
    {
        $client = new RadarrClient(
            'http://localhost:7878',
            'test-api-key',
            null,
            30,
            $this->transport
        );

        $payload = [
            'name' => 'Updated Format',
            'score' => 150,
        ];

        $result = $client->updateCustomFormat(5, $payload);

        $this->assertTrue($result);
        $this->assertCount(1, $this->transport->calls);
        $this->assertSame('PUT', $this->transport->calls[0]['method']);
        $this->assertSame('http://localhost:7878/api/v3/customformat/5', $this->transport->calls[0]['url']);
    }

    public function testDeleteCustomFormatSendsDeleteRequest(): void
    {
        $client = new RadarrClient(
            'http://localhost:7878',
            'test-api-key',
            null,
            30,
            $this->transport
        );

        $result = $client->deleteCustomFormat(5);

        $this->assertTrue($result);
        $this->assertCount(1, $this->transport->calls);
        $this->assertSame('DELETE', $this->transport->calls[0]['method']);
        $this->assertSame('http://localhost:7878/api/v3/customformat/5', $this->transport->calls[0]['url']);
    }

    public function testCreateQualityProfileSendsPostRequest(): void
    {
        $client = new RadarrClient(
            'http://localhost:7878',
            'test-api-key',
            null,
            30,
            $this->transport
        );

        $payload = [
            'name' => 'HD-1080p',
            'cutoff' => 1,
        ];

        $result = $client->createQualityProfile($payload);

        $this->assertSame(10, $result); // CaptureTransport returns id 10
        $this->assertCount(1, $this->transport->calls);
        $this->assertSame('POST', $this->transport->calls[0]['method']);
        $this->assertSame('http://localhost:7878/api/v3/qualityprofile', $this->transport->calls[0]['url']);
    }

    public function testUpdateQualityProfileSendsPutRequest(): void
    {
        $client = new RadarrClient(
            'http://localhost:7878',
            'test-api-key',
            null,
            30,
            $this->transport
        );

        $payload = [
            'name' => 'Ultra HD 4K',
            'cutoff' => 3,
        ];

        $result = $client->updateQualityProfile(3, $payload);

        $this->assertTrue($result);
        $this->assertCount(1, $this->transport->calls);
        $this->assertSame('PUT', $this->transport->calls[0]['method']);
        $this->assertSame('http://localhost:7878/api/v3/qualityprofile/3', $this->transport->calls[0]['url']);
    }

    public function testCreateCustomFormatReturnsIdFromResponse(): void
    {
        $client = new RadarrClient(
            'http://localhost:7878',
            'test-api-key',
            null,
            30,
            new CaptureTransport(200, '{"id":42}')
        );

        $result = $client->createCustomFormat(['name' => 'Test']);

        $this->assertSame(42, $result);
    }

    public function testCreateCustomFormatReturnsZeroWhenIdMissing(): void
    {
        $client = new RadarrClient(
            'http://localhost:7878',
            'test-api-key',
            null,
            30,
            new CaptureTransport(200, '{"name":"Test"}') // no id
        );

        $result = $client->createCustomFormat(['name' => 'Test']);

        $this->assertSame(0, $result);
    }

    public function testCreateCustomFormatReturnsZeroWhenIdNotNumeric(): void
    {
        $client = new RadarrClient(
            'http://localhost:7878',
            'test-api-key',
            null,
            30,
            new CaptureTransport(200, '{"id":"not-a-number"}')
        );

        $result = $client->createCustomFormat(['name' => 'Test']);

        $this->assertSame(0, $result);
    }
}

/**
 * Transport that captures all calls and returns canned responses.
 * Used for testing PUT and DELETE methods.
 */
final class CaptureTransport implements ArrTransportInterface
{
    /**
     * @var list<array{method: string, url: string, headers: array<string>, body: string|null}>
     */
    public array $calls = [];

    public function __construct(
        private readonly int $status = 200,
        private readonly string $body = '{"id":10}'
    ) {
    }

    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        return ['status' => $this->status, 'body' => $this->body];
    }
}
