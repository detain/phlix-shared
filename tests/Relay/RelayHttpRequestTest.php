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

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePathProvider(): array
    {
        return [
            'no leading slash' => ['foo'],
            'literal traversal' => ['/a/../b'],
            'percent-encoded traversal' => ['/%2e%2e/b'],
            'protocol-relative' => ['//evil.com'],
            'embedded NUL' => ["/a\0b"],
            'percent-encoded NUL' => ['/a%00b'],
            'scheme prefix' => ['gopher://x'],
            'backslash' => ['/a\\b'],
            'query in path' => ['/a?b=1'],
            'fragment in path' => ['/a#b'],
            'control char' => ["/a\x01b"],
        ];
    }

    /**
     * @dataProvider unsafePathProvider
     */
    public function test_assert_safe_rejects_unsafe_path(string $path): void
    {
        $req = new RelayHttpRequest('GET', $path, '', [], '');
        $this->expectException(InvalidArgumentException::class);
        $req->assertSafe();
    }

    public function test_assert_safe_rejects_empty_path(): void
    {
        $req = new RelayHttpRequest('GET', '', '', [], '');
        $this->expectException(InvalidArgumentException::class);
        $req->assertSafe();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function disallowedMethodProvider(): array
    {
        return [
            'CONNECT' => ['CONNECT'],
            'TRACE' => ['TRACE'],
            'lowercase garbage' => ['frobnicate'],
            'empty-ish whitespace' => [' '],
        ];
    }

    /**
     * @dataProvider disallowedMethodProvider
     */
    public function test_assert_safe_rejects_disallowed_method(string $method): void
    {
        $req = new RelayHttpRequest($method, '/api/v1/libraries', '', [], '');
        $this->expectException(InvalidArgumentException::class);
        $req->assertSafe();
    }

    public function test_assert_safe_accepts_valid_get(): void
    {
        $req = new RelayHttpRequest('GET', '/api/v1/libraries', '', [], '');
        $req->assertSafe();
        $this->addToAssertionCount(1);
    }

    public function test_assert_safe_accepts_lower_case_method(): void
    {
        $req = new RelayHttpRequest('get', '/api/v1/libraries', '', [], '');
        $req->assertSafe();
        $this->addToAssertionCount(1);
    }

    public function test_from_json_runs_assert_safe(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequest::fromJson('{"method":"GET","path":"/a/../b","headers":{},"body":""}');
    }

    public function test_from_json_rejects_disallowed_method(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequest::fromJson('{"method":"CONNECT","path":"/x","headers":{},"body":""}');
    }

    public function test_round_trip_of_valid_request_still_succeeds(): void
    {
        $req = new RelayHttpRequest('GET', '/api/v1/libraries', 'a=1', ['Accept' => 'application/json'], '');
        $decoded = RelayHttpRequest::fromJson($req->toJson());

        $this->assertSame('GET', $decoded->method);
        $this->assertSame('/api/v1/libraries', $decoded->path);
        $this->assertSame('a=1', $decoded->query);
        $this->assertSame(['Accept' => 'application/json'], $decoded->headers);
        $this->assertSame('', $decoded->body);
    }

    public function test_is_forbidden_header_is_case_insensitive(): void
    {
        $this->assertTrue(RelayHttpRequest::isForbiddenHeader('X-Phlix-Relay-User'));
        $this->assertTrue(RelayHttpRequest::isForbiddenHeader('authorization'));
        $this->assertTrue(RelayHttpRequest::isForbiddenHeader('Cookie'));
        $this->assertTrue(RelayHttpRequest::isForbiddenHeader('X-Forwarded-For'));
        $this->assertFalse(RelayHttpRequest::isForbiddenHeader('Accept'));
        $this->assertFalse(RelayHttpRequest::isForbiddenHeader('X-Phlix-Relay'));
    }

    public function test_without_forbidden_headers_strips_trust_bearing_headers(): void
    {
        $req = new RelayHttpRequest(
            'GET',
            '/api/v1/libraries',
            '',
            [
                'Accept' => 'application/json',
                'X-Phlix-Relay-User' => '42',
                'Authorization' => 'Bearer x',
                'Cookie' => 'session=abc',
                'X-Forwarded-For' => '1.2.3.4',
            ],
            '',
        );

        $clean = $req->withoutForbiddenHeaders();

        $this->assertSame(['Accept' => 'application/json'], $clean->headers);
        // Original is untouched (immutable copy semantics).
        $this->assertArrayHasKey('X-Phlix-Relay-User', $req->headers);
    }
}
