<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use InvalidArgumentException;
use Phlix\Shared\Relay\RelayHttpResponseChunk;
use Phlix\Shared\Relay\RelayHttpResponseCodec;
use Phlix\Shared\Relay\RelayHttpResponseHead;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

/**
 * @covers \Phlix\Shared\Relay\RelayHttpResponseCodec
 * @covers \Phlix\Shared\Relay\RelayHttpResponseHead
 * @covers \Phlix\Shared\Relay\RelayHttpResponseChunk
 */
final class RelayHttpResponseCodecTest extends TestCase
{
    public function test_head_round_trip(): void
    {
        $head = new RelayHttpResponseHead(200, ['Content-Type' => 'application/json'], 27);
        $chunk = RelayHttpResponseCodec::decode(RelayHttpResponseCodec::encodeHead($head));

        $this->assertSame(RelayHttpResponseChunk::KIND_HEAD, $chunk->kind);
        $this->assertNotNull($chunk->head);
        $this->assertSame(200, $chunk->head->status);
        $this->assertSame(['Content-Type' => 'application/json'], $chunk->head->headers);
        $this->assertSame(27, $chunk->head->bodyLength);
    }

    public function test_head_with_null_body_length_for_streaming(): void
    {
        $head = new RelayHttpResponseHead(206, [], null);
        $decoded = RelayHttpResponseHead::fromJson($head->toJson());
        $this->assertNull($decoded->bodyLength);
    }

    public function test_body_round_trip(): void
    {
        $chunk = RelayHttpResponseCodec::decode(RelayHttpResponseCodec::encodeBody('raw bytes'));
        $this->assertSame(RelayHttpResponseChunk::KIND_BODY, $chunk->kind);
        $this->assertNull($chunk->head);
        $this->assertSame('raw bytes', $chunk->body);
    }

    public function test_end_round_trip(): void
    {
        $chunk = RelayHttpResponseCodec::decode(RelayHttpResponseCodec::encodeEnd());
        $this->assertSame(RelayHttpResponseChunk::KIND_END, $chunk->kind);
        $this->assertSame('', $chunk->body);
    }

    public function test_encode_body_rejects_oversize_chunk(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpResponseCodec::encodeBody(str_repeat('x', RelayHttpResponseCodec::MAX_BODY_CHUNK + 1));
    }

    public function test_chunk_body_splits_on_max_boundary(): void
    {
        $body = str_repeat('a', RelayHttpResponseCodec::MAX_BODY_CHUNK)
            . str_repeat('b', 10);
        $chunks = RelayHttpResponseCodec::chunkBody($body);

        $this->assertCount(2, $chunks);

        // Reassemble via decode to prove fidelity.
        $reassembled = '';
        foreach ($chunks as $payload) {
            $decoded = RelayHttpResponseCodec::decode($payload);
            $this->assertSame(RelayHttpResponseChunk::KIND_BODY, $decoded->kind);
            $reassembled .= $decoded->body;
        }
        $this->assertSame($body, $reassembled);
        $this->assertSame(strlen($body), strlen($reassembled));
    }

    public function test_chunk_body_empty_returns_no_chunks(): void
    {
        $this->assertSame([], RelayHttpResponseCodec::chunkBody(''));
    }

    public function test_chunk_body_exactly_max_is_single_chunk(): void
    {
        $body = str_repeat('z', RelayHttpResponseCodec::MAX_BODY_CHUNK);
        $this->assertCount(1, RelayHttpResponseCodec::chunkBody($body));
    }

    public function test_decode_rejects_empty_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpResponseCodec::decode('');
    }

    public function test_decode_rejects_unknown_tag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelayHttpResponseCodec::decode("\x99rest");
    }

    public function test_full_response_stream_reassembly(): void
    {
        $head = new RelayHttpResponseHead(200, ['Content-Type' => 'text/plain'], 5);
        $frames = [
            RelayHttpResponseCodec::encodeHead($head),
            ...RelayHttpResponseCodec::chunkBody('hello'),
            RelayHttpResponseCodec::encodeEnd(),
        ];

        $status = 0;
        $body = '';
        $ended = false;
        foreach ($frames as $payload) {
            $chunk = RelayHttpResponseCodec::decode($payload);
            if ($chunk->kind === RelayHttpResponseChunk::KIND_HEAD && $chunk->head !== null) {
                $status = $chunk->head->status;
            } elseif ($chunk->kind === RelayHttpResponseChunk::KIND_BODY) {
                $body .= $chunk->body;
            } elseif ($chunk->kind === RelayHttpResponseChunk::KIND_END) {
                $ended = true;
            }
        }

        $this->assertSame(200, $status);
        $this->assertSame('hello', $body);
        $this->assertTrue($ended);
    }
}
