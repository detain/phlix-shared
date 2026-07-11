<?php

/**
 * Relay Http Request Chunk.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Relay;

/**
 * Decoded view of one {@see RelayFrameType::HTTP_REQUEST} frame payload.
 *
 * An HTTP request is streamed as a sequence of chunks sharing one request id:
 *   HEAD (method + path + headers) → BODY* (raw body fragments) → END (terminator).
 *
 * @package Phlix\Shared\Relay
 * @since 0.17.0
 */
final readonly class RelayHttpRequestChunk
{
    /** A HEAD chunk: {@see $head} is set, {@see $body} is ''. */
    public const KIND_HEAD = 'head';

    /** A BODY chunk: {@see $body} carries raw request bytes, {@see $head} is null. */
    public const KIND_BODY = 'body';

    /** An END chunk: the request is complete; {@see $head} null, {@see $body} ''. */
    public const KIND_END = 'end';

    /**
     * @param self::KIND_*                $kind The chunk kind.
     * @param RelayHttpRequestHead|null   $head Set only for HEAD chunks.
     * @param string                      $body Raw body bytes for BODY chunks; '' otherwise.
     */
    public function __construct(
        public string $kind,
        public ?RelayHttpRequestHead $head,
        public string $body,
    ) {
    }
}
