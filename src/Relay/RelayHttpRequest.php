<?php

declare(strict_types=1);

namespace Phlix\Shared\Relay;

use InvalidArgumentException;

use function base64_decode;
use function base64_encode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function ord;
use function rawurldecode;
use function str_contains;
use function strlen;
use function strtolower;
use function strtoupper;

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
     * HTTP methods the relay will forward. Anything else is rejected by
     * {@see self::assertSafe()}. Compared case-insensitively (upper-cased).
     *
     * @var list<string>
     */
    public const ALLOWED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * Maximum nesting depth accepted by json_decode when parsing the wire envelope.
     * Set to 512 to match Manifest::fromJson. The 64KB frame cap bounds overall size,
     * so depth is not a security concern here.
     */
    public const MAX_JSON_DEPTH = 512;

    /**
     * Trust-bearing inbound headers the consumer keys auth/identity off and
     * which an untrusted relay producer must never be allowed to set. The DTO
     * does NOT silently strip these in {@see self::fromJson()} (the hub owns
     * identity injection); instead it exposes the set via
     * {@see self::isForbiddenHeader()} and {@see self::withoutForbiddenHeaders()}
     * so the consumer can drop them before forwarding.
     *
     * Names are lower-cased for case-insensitive matching.
     *
     * @var list<string>
     */
    public const STRIPPED_HEADERS = ['x-phlix-relay-user', 'x-forwarded-for', 'authorization', 'cookie'];

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
            $decoded = json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
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

        $request = new self($method, $path, $query, $headers, $body);
        $request->assertSafe();

        return $request;
    }

    /**
     * Validate that the method and path of this (untrusted, hub-tunnelled)
     * request are safe to forward. Throws on any violation; returns void on
     * success. Called automatically at the end of {@see self::fromJson()} so
     * every consumer that deserializes the wire envelope inherits the gate.
     *
     * This validates METHOD and PATH only. It does NOT strip trust-bearing
     * headers — that is the consumer's responsibility via
     * {@see self::isForbiddenHeader()} / {@see self::withoutForbiddenHeaders()},
     * because the consumer owns identity injection (e.g. the relay session's
     * authenticated owner) and the DTO must not silently mutate the envelope.
     *
     * Path rules: must start with a single '/'; must not be protocol-relative
     * ('//…'); must not contain '..' (raw or percent-encoded), a NUL byte
     * (raw or '%00'), a backslash, '://', a '?' (query is a separate field),
     * a '#', or any control character (< 0x20). Percent-encoded sequences are
     * decoded once and re-checked so '%2e%2e' / '%00' are caught.
     *
     * Method rules: must match {@see self::ALLOWED_METHODS} case-insensitively.
     *
     * @throws InvalidArgumentException When the method or path is unsafe.
     *
     * @since 0.11.0
     */
    public function assertSafe(): void
    {
        if (!in_array(strtoupper($this->method), self::ALLOWED_METHODS, true)) {
            throw new InvalidArgumentException(
                'RelayHttpRequest: HTTP method "' . $this->method . '" is not allowed.',
            );
        }

        $path = $this->path;

        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('RelayHttpRequest: "path" must start with "/".');
        }

        // Protocol-relative URL (//host/...) — would target an external origin.
        if (isset($path[1]) && $path[1] === '/') {
            throw new InvalidArgumentException('RelayHttpRequest: "path" must not be protocol-relative.');
        }

        // Re-check the path with percent-encoding decoded once so encoded
        // traversal / NUL bytes are caught alongside their literal forms.
        $decoded = rawurldecode($path);

        foreach ([$path, $decoded] as $candidate) {
            if (str_contains($candidate, '..')) {
                throw new InvalidArgumentException('RelayHttpRequest: "path" must not contain "..".');
            }
            if (str_contains($candidate, "\0")) {
                throw new InvalidArgumentException('RelayHttpRequest: "path" must not contain a NUL byte.');
            }
            if (str_contains($candidate, '\\')) {
                throw new InvalidArgumentException('RelayHttpRequest: "path" must not contain a backslash.');
            }
            if (str_contains($candidate, '://')) {
                throw new InvalidArgumentException('RelayHttpRequest: "path" must not contain "://".');
            }
            if (str_contains($candidate, '?')) {
                throw new InvalidArgumentException('RelayHttpRequest: "path" must not contain a query string.');
            }
            if (str_contains($candidate, '#')) {
                throw new InvalidArgumentException('RelayHttpRequest: "path" must not contain a fragment.');
            }

            $length = strlen($candidate);
            for ($i = 0; $i < $length; $i++) {
                if (ord($candidate[$i]) < 0x20) {
                    throw new InvalidArgumentException(
                        'RelayHttpRequest: "path" must not contain control characters.',
                    );
                }
            }
        }
    }

    /**
     * Whether a header name is a trust-bearing header the consumer must not
     * accept from the untrusted relay producer (see {@see self::STRIPPED_HEADERS}).
     * Case-insensitive.
     *
     * @param string $name Header name.
     *
     * @return bool True when the header must be stripped before forwarding.
     *
     * @since 0.11.0
     */
    public static function isForbiddenHeader(string $name): bool
    {
        return in_array(strtolower($name), self::STRIPPED_HEADERS, true);
    }

    /**
     * Return a copy of this request with all forbidden (trust-bearing) headers
     * removed. The consumer should call this before forwarding so an untrusted
     * relay producer cannot spoof identity/auth headers, then inject the
     * hub-validated owner identity itself.
     *
     * @return self A new instance with {@see self::STRIPPED_HEADERS} dropped.
     *
     * @since 0.11.0
     */
    public function withoutForbiddenHeaders(): self
    {
        $headers = [];
        foreach ($this->headers as $name => $value) {
            if (!self::isForbiddenHeader($name)) {
                $headers[$name] = $value;
            }
        }

        return new self($this->method, $this->path, $this->query, $headers, $this->body);
    }
}
