<?php

/**
 * Relay Http Request Codec Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use InvalidArgumentException;
use Phlix\Shared\Relay\RelayHttpRequestChunk;
use Phlix\Shared\Relay\RelayHttpRequestCodec;
use Phlix\Shared\Relay\RelayHttpRequestHead;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

/**
 * @covers \Phlix\Shared\Relay\RelayHttpRequestCodec
 * @covers \Phlix\Shared\Relay\RelayHttpRequestHead
 * @covers \Phlix\Shared\Relay\RelayHttpRequestChunk
 */
final class RelayHttpRequestCodecTest extends TestCase
{
    public function test_head_round_trip(): void
    {
        $head = new RelayHttpRequestHead('POST', '/api/v1/libraries', 'a=1', ['Content-Type' => 'application/json']);
        $chunk = RelayHttpRequestCodec::decode(RelayHttpRequestCodec::encodeHead($head));

        $this->assertSame(RelayHttpRequestChunk::KIND_HEAD, $chunk->kind);
        $this->assertNotNull($chunk->head);
        $this->assertSame('POST', $chunk->head->method);
        $this->assertSame('/api/v1/libraries', $chunk->head->path);
        $this->assertSame('a=1', $chunk->head->query);
        $this->assertSame(['Content-Type' => 'application/json'], $chunk->head->headers);
    }

    public function test_head_with_empty_query(): void
    {
        $head = new RelayHttpRequestHead('GET', '/api/v1/libraries', '', []);
        $decoded = RelayHttpRequestHead::fromJson($head->toJson());
        $this->assertSame('', $decoded->query);
    }

    public function test_body_round_trip(): void
    {
        $chunk = RelayHttpRequestCodec::decode(RelayHttpRequestCodec::encodeBody('raw bytes'));
        $this->assertSame(RelayHttpRequestChunk::KIND_BODY, $chunk->kind);
        $this->assertNull($chunk->head);
        $this->assertSame('raw bytes', $chunk->body);
    }

    public function test_end_round_trip(): void
    {
        $chunk = RelayHttpRequestCodec::decode(RelayHttpRequestCodec::encodeEnd());
        $this->assertSame(RelayHttpRequestChunk::KIND_END, $chunk->kind);
        $this->assertSame('', $chunk->body);
    }

    public function test_encode_body_rejects_oversize_chunk(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequestCodec::encodeBody(str_repeat('x', RelayHttpRequestCodec::MAX_BODY_CHUNK + 1));
    }

    public function test_chunk_body_splits_on_max_boundary(): void
    {
        $body = str_repeat('a', RelayHttpRequestCodec::MAX_BODY_CHUNK)
            . str_repeat('b', 10);
        $chunks = RelayHttpRequestCodec::chunkBody($body);

        $this->assertCount(2, $chunks);

        // Reassemble via decode to prove fidelity.
        $reassembled = '';
        foreach ($chunks as $payload) {
            $decoded = RelayHttpRequestCodec::decode($payload);
            $this->assertSame(RelayHttpRequestChunk::KIND_BODY, $decoded->kind);
            $reassembled .= $decoded->body;
        }
        $this->assertSame($body, $reassembled);
        $this->assertSame(strlen($body), strlen($reassembled));
    }

    public function test_chunk_body_empty_returns_no_chunks(): void
    {
        $this->assertSame([], RelayHttpRequestCodec::chunkBody(''));
    }

    public function test_chunk_body_exactly_max_is_single_chunk(): void
    {
        $body = str_repeat('z', RelayHttpRequestCodec::MAX_BODY_CHUNK);
        $this->assertCount(1, RelayHttpRequestCodec::chunkBody($body));
    }

    public function test_chunk_body_iterator_splits_correctly(): void
    {
        $body = str_repeat('x', RelayHttpRequestCodec::MAX_BODY_CHUNK)
            . str_repeat('y', 100);
        $chunks = [];
        foreach (RelayHttpRequestCodec::chunkBodyIterator($body) as $payload) {
            $chunks[] = $payload;
        }

        $this->assertCount(2, $chunks);

        // Reassemble via decode.
        $reassembled = '';
        foreach ($chunks as $payload) {
            $decoded = RelayHttpRequestCodec::decode($payload);
            $reassembled .= $decoded->body;
        }
        $this->assertSame($body, $reassembled);
    }

    public function test_chunk_body_iterator_empty(): void
    {
        $chunks = [];
        foreach (RelayHttpRequestCodec::chunkBodyIterator('') as $payload) {
            $chunks[] = $payload;
        }
        $this->assertSame([], $chunks);
    }

    public function test_decode_rejects_empty_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequestCodec::decode('');
    }

    public function test_decode_rejects_unknown_tag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpRequestCodec::decode("\x99rest");
    }

    public function test_full_request_stream_reassembly(): void
    {
        $head = new RelayHttpRequestHead('POST', '/api/v1/upload', '', ['Content-Type' => 'application/octet-stream']);
        $body = 'hello world';
        $frames = [
            RelayHttpRequestCodec::encodeHead($head),
            ...RelayHttpRequestCodec::chunkBody($body),
            RelayHttpRequestCodec::encodeEnd(),
        ];

        $method = '';
        $path = '';
        $body = '';
        $ended = false;
        foreach ($frames as $payload) {
            $chunk = RelayHttpRequestCodec::decode($payload);
            if ($chunk->kind === RelayHttpRequestChunk::KIND_HEAD && $chunk->head !== null) {
                $method = $chunk->head->method;
                $path = $chunk->head->path;
            } elseif ($chunk->kind === RelayHttpRequestChunk::KIND_BODY) {
                $body .= $chunk->body;
            } elseif ($chunk->kind === RelayHttpRequestChunk::KIND_END) {
                $ended = true;
            }
        }

        $this->assertSame('POST', $method);
        $this->assertSame('/api/v1/upload', $path);
        $this->assertSame('hello world', $body);
        $this->assertTrue($ended);
    }

    public function test_tag_constants_match_response_codec(): void
    {
        // Request codec tag values MUST match response codec for wire compatibility.
        $this->assertSame(0x01, RelayHttpRequestCodec::TAG_HEAD);
        $this->assertSame(0x02, RelayHttpRequestCodec::TAG_BODY);
        $this->assertSame(0x03, RelayHttpRequestCodec::TAG_END);
    }

    public function test_max_body_chunk_is_65534(): void
    {
        $this->assertSame(65534, RelayHttpRequestCodec::MAX_BODY_CHUNK);
    }

    public function test_encode_head_produces_tag_followed_by_json(): void
    {
        $head = new RelayHttpRequestHead('GET', '/test', '', []);
        $encoded = RelayHttpRequestCodec::encodeHead($head);

        $this->assertSame("\x01", $encoded[0]);
        $decoded = RelayHttpRequestCodec::decode($encoded);
        $this->assertSame(RelayHttpRequestChunk::KIND_HEAD, $decoded->kind);
    }

    public function test_encode_body_produces_tag_followed_by_bytes(): void
    {
        $encoded = RelayHttpRequestCodec::encodeBody('test');
        $this->assertSame("\x02", $encoded[0]);
        $this->assertSame('test', substr($encoded, 1));
    }

    public function test_encode_end_is_single_tag_byte(): void
    {
        $encoded = RelayHttpRequestCodec::encodeEnd();
        $this->assertSame("\x03", $encoded);
        $this->assertSame(1, strlen($encoded));
    }
}
