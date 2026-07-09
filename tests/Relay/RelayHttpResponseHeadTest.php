<?php

/**
 * Relay Http Response Head Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use InvalidArgumentException;
use Phlix\Shared\Relay\RelayHttpResponseHead;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Relay\RelayHttpResponseHead
 */
final class RelayHttpResponseHeadTest extends TestCase
{
    public function test_round_trip_preserves_all_fields(): void
    {
        $head = new RelayHttpResponseHead(
            200,
            ['Content-Type' => 'application/json', 'X-Phlix-Relay' => '1'],
            12345,
        );

        $decoded = RelayHttpResponseHead::fromJson($head->toJson());

        $this->assertSame(200, $decoded->status);
        $this->assertSame(['Content-Type' => 'application/json', 'X-Phlix-Relay' => '1'], $decoded->headers);
        $this->assertSame(12345, $decoded->bodyLength);
    }

    public function test_null_body_length_for_streaming_response(): void
    {
        $head = new RelayHttpResponseHead(200, ['Transfer-Encoding' => 'chunked'], null);

        $decoded = RelayHttpResponseHead::fromJson($head->toJson());

        $this->assertSame(200, $decoded->status);
        $this->assertNull($decoded->bodyLength);
    }

    public function test_empty_headers_serialize_as_object(): void
    {
        $head = new RelayHttpResponseHead(204, [], 0);
        $this->assertStringContainsString('"headers":{}', $head->toJson());
        $this->assertSame([], RelayHttpResponseHead::fromJson($head->toJson())->headers);
    }

    public function test_from_json_rejects_malformed_json(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('malformed JSON');
        RelayHttpResponseHead::fromJson('{not json');
    }

    public function test_from_json_rejects_missing_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpResponseHead::fromJson('{"headers":{}}');
    }

    public function test_from_json_rejects_non_int_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpResponseHead::fromJson('{"status":"200","headers":{}}');
    }

    public function test_from_json_rejects_non_object_headers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpResponseHead::fromJson('{"status":200,"headers":"json"}');
    }

    public function test_from_json_rejects_non_int_body_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpResponseHead::fromJson('{"status":200,"headers":{},"bodyLength":"unknown"}');
    }

    public function test_from_json_rejects_invalid_header_value_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpResponseHead::fromJson('{"status":200,"headers":{"Content-Type":123},"bodyLength":0}');
    }

    /**
     * Verify json_decode depth cap was raised.
     *
     * With the old depth cap of 8, a 9-level nested object would throw
     * "Maximum stack depth exceeded" from json_decode. With MAX_JSON_DEPTH=512,
     * json_decode (which needs depth 11 for 9 levels) succeeds.
     *
     * The resulting object is not a valid response head (missing 'status'), so
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
            RelayHttpResponseHead::fromJson($json);
        } catch (InvalidArgumentException $e) {
            // Expected: validation fails because top-level lacks required fields.
            // This proves json_decode handled the 9-level depth correctly.
            $this->assertStringContainsString('status', $e->getMessage());
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
        RelayHttpResponseHead::fromJson($json);
    }

    public function test_max_json_depth_constant_is_512(): void
    {
        $this->assertSame(512, RelayHttpResponseHead::MAX_JSON_DEPTH);
    }
}
