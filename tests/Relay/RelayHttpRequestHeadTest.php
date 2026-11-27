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
use Phlix\Shared\Relay\RelayHttpRequest;
use Phlix\Shared\Relay\RelayHttpRequestHead;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_merge;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(\Phlix\Shared\Relay\RelayHttpRequestHead::class)]
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

    // ------------------------------------------------------------------
    // fromJsonGated() — phlix-shared-030: one-call parse that hands back
    // only a head the envelope gate would accept.
    // ------------------------------------------------------------------

    public function test_from_json_gated_accepts_valid_head(): void
    {
        $head = new RelayHttpRequestHead(
            'PUT',
            '/api/v1/libraries/7/items',
            'sync=1',
            ['Content-Type' => 'application/json', 'X-Phlix-Device' => 'hub-tunnel'],
        );

        $decoded = RelayHttpRequestHead::fromJsonGated($head->toJson());

        $this->assertInstanceOf(RelayHttpRequestHead::class, $decoded);
        $this->assertSame('PUT', $decoded->method);
        $this->assertSame('/api/v1/libraries/7/items', $decoded->path);
        $this->assertSame('sync=1', $decoded->query);
        $this->assertSame(
            ['Content-Type' => 'application/json', 'X-Phlix-Device' => 'hub-tunnel'],
            $decoded->headers
        );
    }

    public function test_from_json_gated_inherits_shape_rejections(): void
    {
        // Malformed JSON fails inside fromJson() before the gate ever runs.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RelayHttpRequestHead: malformed JSON');
        RelayHttpRequestHead::fromJsonGated('{not json');
    }

    public function test_from_json_gated_rejection_carries_the_envelope_gate_message(): void
    {
        // Pinning that the head gate is the envelope gate, not a re-
        // implementation: refusals surface RelayHttpRequest's own prefix.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RelayHttpRequest: HTTP method "TRACE" is not allowed.');
        RelayHttpRequestHead::fromJsonGated(self::headJson(['method' => 'TRACE']));
    }

    /**
     * Payloads that clear the shape-only parser but must never clear the
     * gate. Each row is the foot-gun phlix-shared-030 names, from a
     * different arm of RelayHttpRequest::assertSafe().
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function ungatedHeadProvider(): array
    {
        return [
            'TRACE method' => [['method' => 'TRACE'], 'is not allowed'],
            'CONNECT method' => [['method' => 'CONNECT'], 'is not allowed'],
            'whitespace method' => [['method' => ' '], 'is not allowed'],
            'lowercase garbage method' => [['method' => 'frobnicate'], 'is not allowed'],
            'parent traversal' => [['path' => '/a/../b'], 'must not contain ".."'],
            'double-encoded traversal' => [['path' => '/%252e%252e/b'], 'must not contain ".."'],
            'protocol-relative' => [['path' => '//evil.com'], 'protocol-relative'],
            'encoded-slash protocol-relative' => [['path' => '/%2f/evil.com'], 'protocol-relative'],
            'double-encoded protocol-relative' => [['path' => '/%252f/evil.com'], 'protocol-relative'],
            'too-deep encoding' => [['path' => '/a%2525252541'], 'percent-encoded too deeply'],
            'NUL in path' => [['path' => "/a\0b"], 'NUL byte'],
            'percent-encoded NUL' => [['path' => '/a%00b'], 'NUL byte'],
            'backslash in path' => [['path' => '/a\\b'], 'backslash'],
            'scheme prefix in path' => [['path' => '/gopher://x'], '"://"'],
            'query string in path' => [['path' => '/a?b=1'], 'query string'],
            'fragment in path' => [['path' => '/a#b'], 'fragment'],
            'control byte in path' => [['path' => "/a\x01b"], 'control characters'],
            'DEL in path' => [['path' => "/a\x7fb"], 'control characters'],
            'CR in header value' => [
                ['headers' => ['Accept' => "x\ry"]],
                '"header value" must not contain control characters',
            ],
            'LF in header name' => [
                ['headers' => ["X-Bad\nHeader" => 'ok']],
                '"header name" must not contain control characters',
            ],
            'CRLF in query' => [
                ['query' => "a=1\r\nb=2"],
                '"query" must not contain control characters',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $overrides Per-field overrides for the head JSON.
     */
    #[DataProvider('ungatedHeadProvider')]
    public function test_from_json_gated_refuses_heads_that_from_json_accepts(
        array $overrides,
        string $expectedMessage
    ): void {
        $json = self::headJson($overrides);

        // Contrast pin: the shape-only surface really does let these through
        // — this assertion is what makes the gated arm non-vacuous.
        $this->assertInstanceOf(RelayHttpRequestHead::class, RelayHttpRequestHead::fromJson($json));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        RelayHttpRequestHead::fromJsonGated($json);
    }

    public function test_from_json_gated_returns_trust_headers_verbatim_not_stripped(): void
    {
        $json = self::headJson([
            'headers' => [
                'Authorization' => 'Bearer nope',
                'Cookie' => 'session=abc',
                'X-Forwarded-For' => '10.0.0.1',
                'X-Phlix-Relay-User' => '7',
            ],
        ]);

        $decoded = RelayHttpRequestHead::fromJsonGated($json);

        // The gate decides, it never rewrites: identity/trust headers are
        // clean strings and survive; removal stays the consumer's job.
        $this->assertSame([
            'Authorization' => 'Bearer nope',
            'Cookie' => 'session=abc',
            'X-Forwarded-For' => '10.0.0.1',
            'X-Phlix-Relay-User' => '7',
        ], $decoded->headers);
        foreach (array_keys($decoded->headers) as $name) {
            $this->assertTrue(
                RelayHttpRequest::isForbiddenHeader($name),
                $name . ' must be flagged for consumer-side stripping'
            );
        }
    }

    public function test_from_json_gated_preserves_the_raw_path_verbatim(): void
    {
        // '%20' decodes to a space — benign under every deny rule — so the
        // head clears the gate with its RAW bytes intact: the gate peels
        // decode layers for inspection only (verbatim-raw-path law).
        $json = self::headJson(['path' => '/api/v1/a%20b']);

        $decoded = RelayHttpRequestHead::fromJsonGated($json);

        $this->assertSame('/api/v1/a%20b', $decoded->path);
    }

    /**
     * A shape-valid HEAD-chunk envelope with defaults, overridden per case.
     * json_encode escapes the embedded control bytes so json_decode later
     * restores the exact raw payload under test.
     *
     * @param array<string, mixed> $overrides
     */
    private static function headJson(array $overrides): string
    {
        return json_encode(
            array_merge(
                ['method' => 'GET', 'path' => '/api/v1/libraries', 'query' => '', 'headers' => []],
                $overrides
            ),
            JSON_THROW_ON_ERROR
        );
    }
}
