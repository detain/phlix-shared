<?php

/**
 * Relay Http Request Head Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use InvalidArgumentException;
use Phlix\Shared\Relay\RelayHttpRequestHead;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * @covers \Phlix\Shared\Relay\RelayHttpRequestHead
 */
final class RelayHttpRequestHeadTest extends TestCase
{
    public function test_round_trip_preserves_all_fields(): void
    {
        $head = new RelayHttpRequestHead(
            'POST',
            '/api/v1/libraries',
            'a=1&b=2',
            ['Content-Type' => 'application/json', 'X-Phlix-Relay' => '1'],
        );

        $decoded = RelayHttpRequestHead::fromJson($head->toJson());

        $this->assertSame('POST', $decoded->method);
        $this->assertSame('/api/v1/libraries', $decoded->path);
        $this->assertSame('a=1&b=2', $decoded->query);
        $this->assertSame(['Content-Type' => 'application/json', 'X-Phlix-Relay' => '1'], $decoded->headers);
    }

    public function test_empty_query_serializes_correctly(): void
    {
        $head = new RelayHttpRequestHead('GET', '/api/v1/libraries', '', []);
        $decoded = RelayHttpRequestHead::fromJson($head->toJson());

        $this->assertSame('', $decoded->query);
    }

    public function test_empty_headers_serialize_as_object(): void
    {
        $head = new RelayHttpRequestHead('DELETE', '/api/v1/resource', '', []);
        $this->assertStringContainsString('"headers":{}', $head->toJson());
        $this->assertSame([], RelayHttpRequestHead::fromJson($head->toJson())->headers);
    }

    public function test_from_json_rejects_malformed_json(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('malformed JSON');
        RelayHttpRequestHead::fromJson('{not json');
    }

    public function test_from_json_rejects_missing_method(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequestHead::fromJson('{"path":"/","query":"","headers":{}}');
    }

    public function test_from_json_rejects_missing_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequestHead::fromJson('{"method":"GET","query":"","headers":{}}');
    }

    public function test_from_json_rejects_empty_method(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty string');
        RelayHttpRequestHead::fromJson('{"method":"","path":"/","query":"","headers":{}}');
    }

    public function test_from_json_rejects_empty_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty string');
        RelayHttpRequestHead::fromJson('{"method":"GET","path":"","query":"","headers":{}}');
    }

    public function test_from_json_rejects_path_without_leading_slash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with "/"');
        RelayHttpRequestHead::fromJson('{"method":"GET","path":"api/v1","query":"","headers":{}}');
    }

    public function test_from_json_rejects_non_string_method(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequestHead::fromJson('{"method":200,"path":"/","query":"","headers":{}}');
    }

    public function test_from_json_rejects_non_string_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequestHead::fromJson('{"method":"GET","path":123,"query":"","headers":{}}');
    }

    public function test_from_json_rejects_non_string_query(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequestHead::fromJson('{"method":"GET","path":"/","query":123,"headers":{}}');
    }

    public function test_from_json_rejects_non_object_headers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequestHead::fromJson('{"method":"GET","path":"/","query":"","headers":"json"}');
    }

    public function test_from_json_rejects_invalid_header_value_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequestHead::fromJson('{"method":"GET","path":"/","query":"","headers":{"Content-Type":123}}');
    }

    public function test_with_body_size_adds_content_length_header(): void
    {
        $head = new RelayHttpRequestHead('POST', '/api/v1/upload', '', []);
        $withSize = $head->withBodySize(12345);

        $this->assertSame('POST', $withSize->method);
        $this->assertSame('/api/v1/upload', $withSize->path);
        $this->assertSame('', $withSize->query);
        $this->assertSame(['Content-Length' => '12345'], $withSize->headers);
    }

    public function test_with_body_size_updates_existing_content_length(): void
    {
        $head = new RelayHttpRequestHead('POST', '/api/v1/upload', '', ['Content-Length' => '100']);
        $withSize = $head->withBodySize(999);

        $this->assertSame(['Content-Length' => '999'], $withSize->headers);
    }

    /**
     * Verify json_decode depth cap was raised.
     *
     * With the old depth cap of 8, a 9-level nested object would throw
     * "Maximum stack depth exceeded" from json_decode. With MAX_JSON_DEPTH=512,
     * json_decode (which needs depth 11 for 9 levels) succeeds.
     *
     * The resulting object is not a valid request head (missing 'method'), so
     * validation fails after json_decode - but that's fine. The key point is
     * json_decode no longer throws for depth reasons.
     */
    public function test_json_decode_handles_9_level_nesting(): void
    {
        // Build 9 levels of nesting via array (reliable construction).
        $nested = [];
        $current = &$nested;
        for ($i = 0; $i < 9; $i++) {
            $current['level' . $i] = [];
            $current = &$current['level' . $i];
        }
        $json = json_encode($nested, JSON_THROW_ON_ERROR);

        // With depth=8 (old), json_decode throws JsonException.
        // With MAX_JSON_DEPTH=512, json_decode succeeds, then validation fails.
        try {
            RelayHttpRequestHead::fromJson($json);
        } catch (InvalidArgumentException $e) {
            // Expected: validation fails because top-level lacks required fields.
            // This proves json_decode handled the 9-level depth correctly.
            $this->assertStringContainsString('method', $e->getMessage());
        }
    }

    public function test_from_json_rejects_json_exceeding_max_depth(): void
    {
        // Build JSON nested to 512 levels (needs depth 513, exceeds MAX_JSON_DEPTH=512).
        $nested = [];
        $current = &$nested;
        for ($i = 0; $i < 512; $i++) {
            $current['a'] = [];
            $current = &$current['a'];
        }
        $json = json_encode($nested, JSON_THROW_ON_ERROR, 1024);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('malformed JSON');
        RelayHttpRequestHead::fromJson($json);
    }

    public function test_max_json_depth_constant_is_512(): void
    {
        $this->assertSame(512, RelayHttpRequestHead::MAX_JSON_DEPTH);
    }
}
