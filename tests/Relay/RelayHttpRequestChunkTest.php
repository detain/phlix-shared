<?php

/**
 * Relay Http Request Chunk Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use Phlix\Shared\Relay\RelayHttpRequestChunk;
use Phlix\Shared\Relay\RelayHttpRequestHead;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Relay\RelayHttpRequestChunk
 */
final class RelayHttpRequestChunkTest extends TestCase
{
    public function test_kind_constants_exist(): void
    {
        $this->assertSame('head', RelayHttpRequestChunk::KIND_HEAD);
        $this->assertSame('body', RelayHttpRequestChunk::KIND_BODY);
        $this->assertSame('end', RelayHttpRequestChunk::KIND_END);
    }

    public function test_head_chunk_constructor(): void
    {
        $head = new RelayHttpRequestHead('POST', '/api/v1/data', '', ['Content-Type' => 'application/json']);
        $chunk = new RelayHttpRequestChunk(RelayHttpRequestChunk::KIND_HEAD, $head, '');

        $this->assertSame(RelayHttpRequestChunk::KIND_HEAD, $chunk->kind);
        $this->assertNotNull($chunk->head);
        $this->assertSame('POST', $chunk->head->method);
        $this->assertSame('/api/v1/data', $chunk->head->path);
        $this->assertSame('', $chunk->head->query);
        $this->assertSame(['Content-Type' => 'application/json'], $chunk->head->headers);
        $this->assertSame('', $chunk->body);
    }

    public function test_body_chunk_constructor(): void
    {
        $chunk = new RelayHttpRequestChunk(RelayHttpRequestChunk::KIND_BODY, null, 'raw request bytes');

        $this->assertSame(RelayHttpRequestChunk::KIND_BODY, $chunk->kind);
        $this->assertNull($chunk->head);
        $this->assertSame('raw request bytes', $chunk->body);
    }

    public function test_end_chunk_constructor(): void
    {
        $chunk = new RelayHttpRequestChunk(RelayHttpRequestChunk::KIND_END, null, '');

        $this->assertSame(RelayHttpRequestChunk::KIND_END, $chunk->kind);
        $this->assertNull($chunk->head);
        $this->assertSame('', $chunk->body);
    }

    public function test_empty_body_for_head_chunk(): void
    {
        $head = new RelayHttpRequestHead('GET', '/api/v1/test', 'foo=bar', []);
        $chunk = new RelayHttpRequestChunk(RelayHttpRequestChunk::KIND_HEAD, $head, '');

        $this->assertSame('', $chunk->body);
    }

    public function test_empty_body_for_end_chunk(): void
    {
        $chunk = new RelayHttpRequestChunk(RelayHttpRequestChunk::KIND_END, null, '');
        $this->assertSame('', $chunk->body);
    }

    public function test_chunk_is_readonly(): void
    {
        $chunk = new RelayHttpRequestChunk(RelayHttpRequestChunk::KIND_BODY, null, 'test');
        $this->assertTrue((new \ReflectionProperty($chunk, 'kind'))->isReadOnly());
        $this->assertTrue((new \ReflectionProperty($chunk, 'head'))->isReadOnly());
        $this->assertTrue((new \ReflectionProperty($chunk, 'body'))->isReadOnly());
    }
}
