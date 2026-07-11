<?php

/**
 * Relay Http Request Head.
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
 * Immutable head of an HTTP request proxied over the relay tunnel.
 *
 * Carried as the JSON of the HEAD chunk inside an
 * {@see RelayFrameType::HTTP_REQUEST} frame (hub → server). The body itself
 * follows in one or more BODY chunks and a terminating END chunk, so a request
 * larger than a single 65535-byte frame streams across several frames without
 * ever buffering the whole body in one payload.
 *
 * JSON shape:
 *   {"method":"POST","path":"/api/v1/libraries","query":"a=1",
 *    "headers":{"Content-Type":"application/json"}}
 *
 * Unlike {@see RelayHttpRequest}, this DTO carries NO body — the body is
 * streamed separately via BODY chunks. This allows large PUT/PATCH/POST
 * bodies to be sent efficiently without base64 encoding overhead.
 *
 * @package Phlix\Shared\Relay
 * @since 0.17.0
 */
final readonly class RelayHttpRequestHead
{
    /**
     * Maximum nesting depth accepted by json_decode when parsing the wire envelope.
     * Set to 512 to match Manifest::fromJson. The 64KB frame cap bounds overall size,
     * so depth is not a security concern here.
     */
    public const MAX_JSON_DEPTH = 512;

    /**
     * @param string                $method  Upper-case HTTP method (GET/POST/PUT/PATCH/DELETE/…).
     * @param string                $path    Request path (no query string), e.g. /api/v1/libraries.
     * @param string                $query   Raw query string without the leading '?' (may be '').
     * @param array<string, string> $headers Request headers (name => value).
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $query,
        public array $headers,
    ) {
    }

    /**
     * Serialize the head to its JSON representation.
     *
     * @return string JSON text.
     *
     * @throws \JsonException When encoding fails.
     *
     * @since 0.17.0
     */
    public function toJson(): string
    {
        return json_encode([
            'method' => $this->method,
            'path' => $this->path,
            'query' => $this->query,
            'headers' => (object) $this->headers,
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
     * @since 0.17.0
     */
    public static function fromJson(string $json): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('RelayHttpRequestHead: malformed JSON: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('RelayHttpRequestHead: JSON must be an object.');
        }

        $method = $decoded['method'] ?? null;
        $path = $decoded['path'] ?? null;
        $query = $decoded['query'] ?? '';
        $rawHeaders = $decoded['headers'] ?? [];

        if (!is_string($method) || $method === '') {
            throw new InvalidArgumentException('RelayHttpRequestHead: "method" must be a non-empty string.');
        }
        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException('RelayHttpRequestHead: "path" must be a non-empty string.');
        }
        if ($path[0] !== '/') {
            throw new InvalidArgumentException('RelayHttpRequestHead: "path" must start with "/".');
        }
        if (!is_string($query)) {
            throw new InvalidArgumentException('RelayHttpRequestHead: "query" must be a string.');
        }
        if (!is_array($rawHeaders)) {
            throw new InvalidArgumentException('RelayHttpRequestHead: "headers" must be an object.');
        }

        $headers = [];
        foreach ($rawHeaders as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new InvalidArgumentException('RelayHttpRequestHead: header names and values must be strings.');
            }
            $headers[$name] = $value;
        }

        return new self($method, $path, $query, $headers);
    }

    /**
     * Return a copy of this head with the Content-Length header set/updated.
     *
     * @param int $contentLength The body size in bytes.
     *
     * @return self A new instance with the Content-Length header added/updated.
     *
     * @since 0.17.0
     */
    public function withBodySize(int $contentLength): self
    {
        $headers = $this->headers;
        $headers['Content-Length'] = (string) $contentLength;

        return new self($this->method, $this->path, $this->query, $headers);
    }
}
