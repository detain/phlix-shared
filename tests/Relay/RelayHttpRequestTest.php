<?php

/**
 * Relay Http Request Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use InvalidArgumentException;
use Phlix\Shared\Relay\RelayHttpRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Phlix\Shared\Relay\RelayHttpRequest::class)]
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
            'DEL (0x7F)' => ["/a\x7fb"],
            'percent-encoded DEL' => ['/a%7fb'],
            'encoded-slash protocol-relative' => ['/%2f/evil.com'],
            'upper-case encoded-slash protocol-relative' => ['/%2F%2Fevil.com'],
            'double-encoded traversal' => ['/%252e%252e/b'],
            'double-encoded NUL' => ['/a%2500b'],
            'double-encoded protocol-relative' => ['/%252f/evil.com'],
            'double-encoded backslash' => ['/a%255cb'],
            'too deeply encoded' => ['/a%2525252541'],
        ];
    }

    #[DataProvider('unsafePathProvider')]
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

    #[DataProvider('disallowedMethodProvider')]
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

    /**
     * Verify json_decode depth cap was raised.
     *
     * With the old depth cap of 8, a 9-level nested object would throw
     * "Maximum stack depth exceeded" from json_decode. With MAX_JSON_DEPTH=512,
     * json_decode (which needs depth 11 for 9 levels) succeeds.
     *
     * The resulting object is not a valid envelope (missing 'method'), so
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
            RelayHttpRequest::fromJson($json);
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
        RelayHttpRequest::fromJson($json);
    }

    public function test_from_json_still_rejects_malformed_json(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('malformed JSON');
        RelayHttpRequest::fromJson('{not json');
    }

    public function test_max_json_depth_constant_is_512(): void
    {
        $this->assertSame(512, RelayHttpRequest::MAX_JSON_DEPTH);
    }

    /**
     * LW-052 [review shared__secaudit Major #1]: the gate must cover the
     * whole request head. Every control byte (ord < 0x20, killed the same
     * way the path loop has always killed them) and DEL (0x7F) are refused
     * in header names, header values and the query string.
     *
     * @return array<string, array{string}>
     */
    public static function controlByteProvider(): array
    {
        return [
            'CR' => ["b\rc"],
            'LF' => ["b\nc"],
            'CRLF header injection' => ["ok\r\nX-Phlix-Relay-User: 42"],
            'NUL' => ["b\0c"],
            'SOH (0x01)' => ["b\x01c"],
            'unit separator (0x1F)' => ["b\x1fc"],
            'DEL (0x7F)' => ["b\x7fc"],
        ];
    }

    /**
     * Red-first at the parent commit: the value screen covered only the
     * path, so these payloads cleared assertSafe() untouched.
     *
     */
    #[DataProvider('controlByteProvider')]
    public function test_assert_safe_rejects_control_bytes_in_header_values(string $value): void
    {
        $req = new RelayHttpRequest('GET', '/x', '', ['Accept' => $value], '');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"header value" must not contain control characters');
        $req->assertSafe();
    }

    #[DataProvider('controlByteProvider')]
    public function test_assert_safe_rejects_control_bytes_in_header_names(string $name): void
    {
        $req = new RelayHttpRequest('GET', '/x', '', [$name => 'ok'], '');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"header name" must not contain control characters');
        $req->assertSafe();
    }

    #[DataProvider('controlByteProvider')]
    public function test_assert_safe_rejects_control_bytes_in_query(string $query): void
    {
        $req = new RelayHttpRequest('GET', '/x', $query, [], '');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"query" must not contain control characters');
        $req->assertSafe();
    }

    /**
     * The untrusted wire path: a hub-produced envelope carrying a CR/LF
     * injection in a header value must be refused by fromJson() itself,
     * without the consumer touching assertSafe() directly. The \r\n escape
     * below is decoded by json_decode into real CR LF bytes.
     */
    public function test_from_json_rejects_crlf_injected_header_value(): void
    {
        $json = '{"method":"GET","path":"/x","headers":{"Accept":"a\r\nX-Phlix-Relay-User: 42"},"body":""}';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"header value" must not contain control characters');
        RelayHttpRequest::fromJson($json);
    }

    /**
     * The screen also closes the trust-set bypass: a padded control byte in
     * a forbidden header name would dodge the exact-match in
     * isForbiddenHeader() — the head-wide screen refuses it at the gate
     * instead.
     */
    public function test_control_byte_cannot_dodge_the_forbidden_header_match(): void
    {
        $req = new RelayHttpRequest('GET', '/x', '', ["X-Forwarded-For\r" => '1.2.3.4'], '');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"header name" must not contain control characters');
        $req->assertSafe();
    }

    /**
     * Boundary pin for the new screen: every printable byte (0x20 space
     * through 0x7E tilde) stays legal in the head — the screen kills
     * control characters, not HTTP-visible text.
     */
    public function test_assert_safe_accepts_printable_bytes_in_head_fields(): void
    {
        $visible = '';
        for ($byte = 0x20; $byte <= 0x7E; $byte++) {
            $visible .= chr($byte);
        }

        $req = new RelayHttpRequest('GET', '/x', 'q=' . $visible, ['X-Visible' => $visible], '');
        $req->assertSafe();
        $this->addToAssertionCount(1);
    }

    /**
     * The path screen now shares the head's control-byte law: DEL (0x7F)
     * is refused alongside sub-0x20 bytes, with the path's long-standing
     * message. Red-first at the parent: DEL in a path cleared the gate.
     */
    public function test_path_rejects_del_with_the_control_character_message(): void
    {
        $req = new RelayHttpRequest('GET', "/a\x7fb", '', [], '');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"path" must not contain control characters');
        $req->assertSafe();
    }

    /**
     * Red-first at the parent: the protocol-relative test ran on the raw
     * path only, so '/%2f/evil.com' (decoding to '//evil.com') cleared it.
     */
    public function test_encoded_slash_protocol_relative_path_is_refused(): void
    {
        $req = new RelayHttpRequest('GET', '/%2f/evil.com', '', [], '');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"path" must not be protocol-relative');
        $req->assertSafe();
    }

    /**
     * Red-first at the parent: the path was decoded once only, so a
     * double-encoded traversal ('%252e%252e' -> '%2e%2e' -> '..') reached
     * any hop that decodes again.
     */
    public function test_double_encoded_traversal_is_refused(): void
    {
        $req = new RelayHttpRequest('GET', '/api/%252e%252e/admin', '', [], '');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"path" must not contain ".."');
        $req->assertSafe();
    }

    /**
     * Fail-closed bound: four decoding layers are inspected; a path that is
     * still changing past that is refused rather than half-checked.
     */
    public function test_path_encoded_past_the_layer_bound_is_refused(): void
    {
        // Four layers decode cleanly ('%25252541' -> ... -> 'A') and pass.
        (new RelayHttpRequest('GET', '/a%25252541', '', [], ''))->assertSafe();
        $this->addToAssertionCount(1);

        // A fifth layer is refused.
        $req = new RelayHttpRequest('GET', '/a%2525252541', '', [], '');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"path" is percent-encoded too deeply');
        $req->assertSafe();
    }

    /**
     * Verbatim-raw-path law: the gate inspects decoded layers but never
     * rewrites the envelope — benign encoded paths keep their raw bytes
     * through assertSafe() and a fromJson() round trip.
     */
    public function test_gate_preserves_the_raw_path_verbatim(): void
    {
        $raw = '/api/v1/items/a%20b%2Fc%25d';
        $req = new RelayHttpRequest('GET', $raw, '', [], '');
        $req->assertSafe();
        $this->assertSame($raw, $req->path);

        $decoded = RelayHttpRequest::fromJson($req->toJson());
        $this->assertSame($raw, $decoded->path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paddedForbiddenHeaderProvider(): array
    {
        return [
            'trailing space' => ['Authorization '],
            'leading space' => [' Authorization'],
            'trailing tab' => ["Cookie\t"],
            'both sides, mixed case' => [" \tX-Phlix-Relay-User \t"],
            'padded x-forwarded-for' => ['x-forwarded-for  '],
        ];
    }

    /**
     * Red-first at the parent: the trust-set match was exact, so a padded
     * name ("Authorization ") was not forbidden — and lenient downstream
     * parsers trim it back to the real header.
     *
     */
    #[DataProvider('paddedForbiddenHeaderProvider')]
    public function test_padded_forbidden_header_name_is_still_forbidden(string $name): void
    {
        $this->assertTrue(RelayHttpRequest::isForbiddenHeader($name));
    }

    public function test_without_forbidden_headers_strips_padded_trust_header(): void
    {
        $req = new RelayHttpRequest(
            'GET',
            '/x',
            '',
            ['Accept' => 'application/json', 'Authorization ' => 'Bearer spoofed'],
            '',
        );
        $req->assertSafe();

        $this->assertSame(['Accept' => 'application/json'], $req->withoutForbiddenHeaders()->headers);
    }

    public function test_padding_does_not_widen_the_trust_set(): void
    {
        $this->assertFalse(RelayHttpRequest::isForbiddenHeader(' Accept '));
        $this->assertFalse(RelayHttpRequest::isForbiddenHeader('Authorization-Extra'));
        $this->assertFalse(RelayHttpRequest::isForbiddenHeader('X-Authorization'));
    }
}
