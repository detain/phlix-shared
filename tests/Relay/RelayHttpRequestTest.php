<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use InvalidArgumentException;
use Phlix\Shared\Relay\RelayHttpRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Relay\RelayHttpRequest
 */
final class RelayHttpRequestTest extends TestCase
{
    public function test_round_trip_preserves_all_fields(): void
    {
        $req = new RelayHttpRequest(
            'POST',
            '/api/v1/media',
            'libraryId=abc&page=2',
            ['Accept' => 'application/json', 'X-Phlix-Relay' => '1'],
            '{"hello":"world"}',
        );

        $decoded = RelayHttpRequest::fromJson($req->toJson());

        $this->assertSame('POST', $decoded->method);
        $this->assertSame('/api/v1/media', $decoded->path);
        $this->assertSame('libraryId=abc&page=2', $decoded->query);
        $this->assertSame(['Accept' => 'application/json', 'X-Phlix-Relay' => '1'], $decoded->headers);
        $this->assertSame('{"hello":"world"}', $decoded->body);
    }

    public function test_binary_body_survives_round_trip(): void
    {
        $body = "\x00\x01\x02\xff\xfe binary \x80";
        $req = new RelayHttpRequest('PUT', '/x', '', [], $body);

        $this->assertSame($body, RelayHttpRequest::fromJson($req->toJson())->body);
    }

    public function test_empty_headers_serialize_as_object(): void
    {
        $req = new RelayHttpRequest('GET', '/', '', [], '');
        $this->assertStringContainsString('"headers":{}', $req->toJson());
        $this->assertSame([], RelayHttpRequest::fromJson($req->toJson())->headers);
    }

    public function test_from_json_rejects_malformed_json(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequest::fromJson('{not json');
    }

    public function test_from_json_rejects_missing_method(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequest::fromJson('{"path":"/x"}');
    }

    public function test_from_json_rejects_non_string_header_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequest::fromJson('{"method":"GET","path":"/x","headers":{"A":1},"body":""}');
    }

    public function test_from_json_rejects_invalid_base64_body(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequest::fromJson('{"method":"GET","path":"/x","headers":{},"body":"!!!not base64!!!"}');
    }
}
