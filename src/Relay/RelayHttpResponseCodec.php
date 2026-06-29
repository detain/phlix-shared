<?php

declare(strict_types=1);

namespace Phlix\Shared\Relay;

use InvalidArgumentException;

use function chr;
use function ord;
use function strlen;
use function substr;

/**
 * Encodes and decodes the chunk sub-frames carried inside the payload of an
 * {@see RelayFrameType::HTTP_RESPONSE} frame.
 *
 * Each HTTP_RESPONSE frame payload is `[1-byte tag][data]`:
 *   - tag {@see self::TAG_HEAD} (0x01): `data` is {@see RelayHttpResponseHead} JSON.
 *   - tag {@see self::TAG_BODY} (0x02): `data` is raw body bytes (<= {@see self::MAX_BODY_CHUNK}).
 *   - tag {@see self::TAG_END}  (0x03): `data` is empty; the response is complete.
 *
 * A producer emits exactly one HEAD, then zero or more BODY chunks, then one
 * END. The 1-byte tag keeps the wire fully compatible with the existing relay
 * frame codec (the chunk is just opaque payload to the binary framer), while
 * allowing a body of any size to stream across many <=65535-byte frames.
 *
 * @package Phlix\Shared\Relay
 * @since 0.10.0
 */
final class RelayHttpResponseCodec
{
    /** HEAD chunk tag byte. */
    public const TAG_HEAD = 0x01;

    /** BODY chunk tag byte. */
    public const TAG_BODY = 0x02;

    /** END chunk tag byte. */
    public const TAG_END = 0x03;

    /**
     * Largest body slice that fits one frame: 65535 payload bytes minus the
     * 1-byte chunk tag. Producers MUST split larger bodies on this boundary.
     */
    public const MAX_BODY_CHUNK = 65534;

    /**
     * Encode the HEAD chunk for an HTTP_RESPONSE frame payload.
     *
     * @param RelayHttpResponseHead $head Response head (status + headers + length).
     *
     * @return string Payload bytes (tag + JSON).
     *
     * @throws \JsonException When the head cannot be JSON-encoded.
     *
     * @since 0.10.0
     */
    public static function encodeHead(RelayHttpResponseHead $head): string
    {
        return chr(self::TAG_HEAD) . $head->toJson();
    }

    /**
     * Encode a BODY chunk for an HTTP_RESPONSE frame payload.
     *
     * @param string $bodyChunk Raw body slice (<= {@see self::MAX_BODY_CHUNK} bytes).
     *
     * @return string Payload bytes (tag + body).
     *
     * @throws InvalidArgumentException When the slice exceeds {@see self::MAX_BODY_CHUNK}.
     *
     * @since 0.10.0
     */
    public static function encodeBody(string $bodyChunk): string
    {
        if (strlen($bodyChunk) > self::MAX_BODY_CHUNK) {
            throw new InvalidArgumentException(
                sprintf(
                    'RelayHttpResponseCodec: body chunk %d exceeds max %d bytes.',
                    strlen($bodyChunk),
                    self::MAX_BODY_CHUNK,
                ),
            );
        }

        return chr(self::TAG_BODY) . $bodyChunk;
    }

    /**
     * Encode the END chunk for an HTTP_RESPONSE frame payload.
     *
     * @return string Payload bytes (single tag byte).
     *
     * @since 0.10.0
     */
    public static function encodeEnd(): string
    {
        return chr(self::TAG_END);
    }

    /**
     * Split a full body into BODY chunk payloads of at most {@see self::MAX_BODY_CHUNK}.
     *
     * Returns an empty list for an empty body (the HEAD + END alone convey a
     * zero-length body).
     *
     * @param string $body Full response body bytes.
     *
     * @return list<string> Encoded BODY chunk payloads, in order.
     *
     * @since 0.10.0
     */
    public static function chunkBody(string $body): array
    {
        $chunks = [];
        $length = strlen($body);
        for ($offset = 0; $offset < $length; $offset += self::MAX_BODY_CHUNK) {
            $chunks[] = self::encodeBody(substr($body, $offset, self::MAX_BODY_CHUNK));
        }

        return $chunks;
    }

    /**
     * Split a full body into BODY chunk payloads yields them lazily.
     *
     * Memory-efficient alternative to {@see self::chunkBody()} for streaming
     * very large bodies. Yields encoded BODY chunk payloads one at a time.
     *
     * @param string $body Full response body bytes.
     *
     * @return \Generator<int, string> Yields encoded BODY chunk payloads, in order.
     *
     * @since 0.10.0
     */
    public static function chunkBodyIterator(string $body): \Generator
    {
        $length = strlen($body);
        for ($offset = 0; $offset < $length; $offset += self::MAX_BODY_CHUNK) {
            yield self::encodeBody(substr($body, $offset, self::MAX_BODY_CHUNK));
        }
    }

    /**
     * Decode one HTTP_RESPONSE frame payload into a typed chunk.
     *
     * @param string $payload The frame payload (tag + data).
     *
     * @return RelayHttpResponseChunk
     *
     * @throws InvalidArgumentException When the payload is empty or the tag is unknown.
     *
     * @since 0.10.0
     */
    public static function decode(string $payload): RelayHttpResponseChunk
    {
        if ($payload === '') {
            throw new InvalidArgumentException('RelayHttpResponseCodec: empty chunk payload.');
        }

        $tag = ord($payload[0]);
        $data = substr($payload, 1);

        return match ($tag) {
            self::TAG_HEAD => new RelayHttpResponseChunk(
                RelayHttpResponseChunk::KIND_HEAD,
                RelayHttpResponseHead::fromJson($data),
                '',
            ),
            self::TAG_BODY => new RelayHttpResponseChunk(
                RelayHttpResponseChunk::KIND_BODY,
                null,
                $data,
            ),
            self::TAG_END => new RelayHttpResponseChunk(
                RelayHttpResponseChunk::KIND_END,
                null,
                '',
            ),
            default => throw new InvalidArgumentException(
                sprintf('RelayHttpResponseCodec: unknown chunk tag 0x%02X.', $tag),
            ),
        };
    }
}
