<?php

/**
 * Relay Http Response Head.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Relay;

use InvalidArgumentException;

use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Immutable head of an HTTP response proxied over the relay tunnel.
 *
 * Carried as the JSON of the HEAD chunk inside an
 * {@see RelayFrameType::HTTP_RESPONSE} frame (server → hub). The body itself
 * follows in one or more BODY chunks and a terminating END chunk, so a response
 * larger than a single 65535-byte frame streams across several frames without
 * ever buffering the whole body in one payload.
 *
 * JSON shape:
 *   {"status":200,"headers":{"Content-Type":"application/json"},"bodyLength":27}
 *
 * `bodyLength` is the total body size in bytes when known (buffered responses,
 * P1) or null when the producer is streaming a body of unknown length (P3); in
 * the streaming case the END chunk is the sole completion signal.
 *
 * @package Phlix\Shared\Relay
 * @since 0.10.0
 */
final readonly class RelayHttpResponseHead
{
    /**
     * Maximum nesting depth accepted by json_decode when parsing the wire envelope.
     * Set to 512 to match Manifest::fromJson. The 64KB frame cap bounds overall size,
     * so depth is not a security concern here.
     */
    public const MAX_JSON_DEPTH = 512;

    /**
     * @param int                   $status     HTTP status code.
     * @param array<string, string> $headers    Response headers (name => value).
     * @param int|null              $bodyLength Total body length in bytes, or null when streaming.
     */
    public function __construct(
        public int $status,
        public array $headers,
        public ?int $bodyLength = null,
    ) {
    }

    /**
     * Serialize the head to its JSON representation.
     *
     * @return string JSON text.
     *
     * @throws \JsonException When encoding fails.
     *
     * @since 0.10.0
     */
    public function toJson(): string
    {
        return json_encode([
            'status' => $this->status,
            'headers' => (object) $this->headers,
            'bodyLength' => $this->bodyLength,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Parse a head JSON string.
     *
     * @param string $json JSON text from a HEAD chunk.
     *
     * @return self
     *
     * @throws InvalidArgumentException When the JSON is malformed or a field is wrong-typed.
     *
     * @since 0.10.0
     */
    public static function fromJson(string $json): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('RelayHttpResponseHead: malformed JSON: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('RelayHttpResponseHead: JSON must be an object.');
        }

        $status = $decoded['status'] ?? null;
        $rawHeaders = $decoded['headers'] ?? [];
        $bodyLength = $decoded['bodyLength'] ?? null;

        if (!is_int($status)) {
            throw new InvalidArgumentException('RelayHttpResponseHead: "status" must be an integer.');
        }
        if (!is_array($rawHeaders)) {
            throw new InvalidArgumentException('RelayHttpResponseHead: "headers" must be an object.');
        }
        if ($bodyLength !== null && !is_int($bodyLength)) {
            throw new InvalidArgumentException('RelayHttpResponseHead: "bodyLength" must be an integer or null.');
        }

        $headers = [];
        foreach ($rawHeaders as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new InvalidArgumentException('RelayHttpResponseHead: header names and values must be strings.');
            }
            $headers[$name] = $value;
        }

        return new self($status, $headers, $bodyLength);
    }
}
