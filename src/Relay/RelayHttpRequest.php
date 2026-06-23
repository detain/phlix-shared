<?php

declare(strict_types=1);

namespace Phlix\Shared\Relay;

use InvalidArgumentException;

use function base64_decode;
use function base64_encode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Immutable envelope for a single HTTP request proxied over the relay tunnel.
 *
 * Carried as the JSON payload of an {@see RelayFrameType::HTTP_REQUEST} frame
 * (hub → server). The body is base64-encoded in the JSON so arbitrary bytes
 * survive the text encoding; everything else is plain JSON.
 *
 * Wire JSON shape:
 *   {"method":"GET","path":"/api/v1/libraries","query":"a=1&b=2",
 *    "headers":{"Accept":"application/json"},"body":"<base64>"}
 *
 * The whole frame payload must fit in 65535 bytes (the relay frame limit), so
 * this envelope is for browse-style requests with small/empty bodies. Producers
 * SHOULD reject (413) a request whose encoded envelope would exceed the limit
 * rather than truncate it.
 *
 * @package Phlix\Shared\Relay
 * @since 0.10.0
 */
final readonly class RelayHttpRequest
{
    /**
     * @param string                $method  Upper-case HTTP method (GET/POST/…).
     * @param string                $path    Request path (no query string), e.g. /api/v1/libraries.
     * @param string                $query   Raw query string without the leading '?' (may be '').
     * @param array<string, string> $headers Request headers (name => value).
     * @param string                $body    Raw request body bytes (may be '').
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $query,
        public array $headers,
        public string $body,
    ) {
    }

    /**
     * Serialize to the wire JSON string for an HTTP_REQUEST frame payload.
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
            'method' => $this->method,
            'path' => $this->path,
            'query' => $this->query,
            'headers' => (object) $this->headers,
            'body' => base64_encode($this->body),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Parse a wire JSON string back into a request envelope.
     *
     * @param string $json JSON text from an HTTP_REQUEST frame payload.
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
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('RelayHttpRequest: malformed JSON: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('RelayHttpRequest: JSON must be an object.');
        }

        $method = $decoded['method'] ?? null;
        $path = $decoded['path'] ?? null;
        $query = $decoded['query'] ?? '';
        $rawHeaders = $decoded['headers'] ?? [];
        $bodyEncoded = $decoded['body'] ?? '';

        if (!is_string($method) || $method === '') {
            throw new InvalidArgumentException('RelayHttpRequest: "method" must be a non-empty string.');
        }
        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException('RelayHttpRequest: "path" must be a non-empty string.');
        }
        if (!is_string($query)) {
            throw new InvalidArgumentException('RelayHttpRequest: "query" must be a string.');
        }
        if (!is_array($rawHeaders)) {
            throw new InvalidArgumentException('RelayHttpRequest: "headers" must be an object.');
        }
        if (!is_string($bodyEncoded)) {
            throw new InvalidArgumentException('RelayHttpRequest: "body" must be a base64 string.');
        }

        $headers = [];
        foreach ($rawHeaders as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new InvalidArgumentException('RelayHttpRequest: header names and values must be strings.');
            }
            $headers[$name] = $value;
        }

        $body = base64_decode($bodyEncoded, true);
        if ($body === false) {
            throw new InvalidArgumentException('RelayHttpRequest: "body" is not valid base64.');
        }

        return new self($method, $path, $query, $headers, $body);
    }
}
