<?php

/**
 * Relay Http Request.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
 * SECURITY: `HTTP_REQUEST` is a hub → server (untrusted-producer) frame.
 * The gate — `ALLOWED_METHODS`, the path traversal/encoding denylist, the
 * head-wide control-byte screen (`assertSafe()` covers method, path, query
 * and every header name/value), and the `STRIPPED_HEADERS` trust set — is
 * defined ONLY here and is inherited by every consumer that deserializes via
 * `fromJson()` (it ends in `assertSafe()`). The public constructor is the
 * TRUSTED-INPUT path: it runs NO gate, so an envelope reassembled by hand
 * (e.g. streaming HEAD+BODY reconstruction) MUST have `assertSafe()` called
 * on it before dispatch, and `withoutForbiddenHeaders()` applied before
 * forwarding upstream. The streaming sibling `RelayHttpRequestHead`
 * deliberately parses shape only and defers this gate to that
 * reconstitution; consumers of the head MUST NOT trust its
 * method/path/query/headers until it has been gated here — see that
 * class's SECURITY CONTRACT.
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
     * How many percent-decoding layers {@see self::assertSafe()} peels off
     * the path, re-checking each one. A path that still changes under
     * decoding after this many layers is refused outright (fail-closed)
     * rather than half-checked: no legitimate route is percent-encoded four
     * times over, and an unchecked deeper layer is exactly where a
     * double-decoding hop would find '..' or '//'.
     */
    private const MAX_PATH_DECODE_LAYERS = 4;

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
     * Validate that the head of this (untrusted, hub-tunnelled) request is
     * safe to forward: method, path, query and every header name and value.
     * Throws on any violation; returns void on success. Called automatically
     * at the end of {@see self::fromJson()} so every consumer that
     * deserializes the wire envelope inherits the gate.
     *
     * It does NOT strip trust-bearing headers — that is the consumer's
     * responsibility via {@see self::isForbiddenHeader()} /
     * {@see self::withoutForbiddenHeaders()}, because the consumer owns
     * identity injection (e.g. the relay session's authenticated owner) and
     * the DTO must not silently mutate the envelope.
     *
     * Path rules: must start with a single '/'; must not be protocol-relative
     * ('//…'); must not contain '..', a NUL byte, a backslash, '://', a '?'
     * (query is a separate field), a '#', or any control character (< 0x20)
     * or DEL (0x7F). Every rule — the protocol-relative one included — is
     * applied to the raw path AND to each percent-decoding layer of it
     * (decoded repeatedly until it stops changing, up to
     * {@see self::MAX_PATH_DECODE_LAYERS} layers; a path still changing past
     * that is refused), so '%2e%2e', '%00', '/%2f/evil.com' and their
     * double-encoded forms ('%252e%252e', '/%252f/…') are all caught.
     *
     * Verbatim-raw-path law: the gate never rewrites the path — the decoded
     * layers are inspected and discarded, and the envelope keeps the raw
     * bytes the producer sent. Consumers forward {@see self::$path} verbatim
     * (phlix-server's RelayConsumer does) and must not normalize it into a
     * form this gate did not inspect.
     *
     * Method rules: must match {@see self::ALLOWED_METHODS} case-insensitively.
     *
     * Query and header rules (head-wide screen): the query string and every header name
     * and value must not contain any control character (ord < 0x20) or DEL
     * (0x7F). This kills CR/LF header- and request-line injection, NUL
     * truncation, and every other control byte an untrusted hub producer
     * could smuggle into a reconstituted HTTP head — the screen the path loop
     * has always applied, widened to the whole head. The same screen closes a
     * trust-set bypass: a name like "X-Forwarded-For\r" would slip past the
     * exact match in {@see self::isForbiddenHeader()}, and the value screen
     * likewise covers multi-line values. The path screen now refuses DEL as
     * well, so the whole head shares one control-byte law. Independently,
     * {@see self::isForbiddenHeader()} trims SP/HTAB padding before its
     * match, so "Authorization " cannot dodge the trust set either.
     *
     * @throws InvalidArgumentException When the method, path, query or any
     *                                  header name/value is unsafe.
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

        // Check the raw path and every percent-decoding layer of it, so an
        // encoded form ('%2e%2e', '%2f', '%00', and their double-encoded
        // '%25…' wrappers) is caught alongside its literal one. The raw path
        // itself is never rewritten (verbatim-raw-path law).
        foreach (self::pathDecodingLayers($path) as $candidate) {
            // Protocol-relative URL (//host/...) — would target an external
            // origin. Checked per layer: '/%2f/evil.com' decodes to it.
            if (isset($candidate[1]) && $candidate[0] === '/' && $candidate[1] === '/') {
                throw new InvalidArgumentException('RelayHttpRequest: "path" must not be protocol-relative.');
            }
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

            self::assertNoControlCharacters($candidate, 'path');
        }

        // The rest of the head: the same per-byte screen (control bytes and
        // DEL) the path layers above get, applied to the query and every
        // header name and value, so the documented single gate covers the
        // whole head on the untrusted wire.
        self::assertNoControlCharacters($this->query, 'query');

        foreach ($this->headers as $name => $value) {
            self::assertNoControlCharacters($name, 'header name');
            self::assertNoControlCharacters($value, 'header value');
        }
    }

    /**
     * The raw path followed by each distinct percent-decoding layer of it,
     * decoded repeatedly until it stops changing. Refuses (fail-closed) a
     * path that is still changing after {@see self::MAX_PATH_DECODE_LAYERS}
     * layers, so no layer a double-decoding hop could reach goes unchecked.
     *
     * @param string $path Raw request path.
     *
     * @return list<string> Raw path first, then each decoded layer in order.
     *
     * @throws InvalidArgumentException When the path is encoded more deeply
     *                                  than the gate inspects.
     */
    private static function pathDecodingLayers(string $path): array
    {
        $layers = [$path];
        $current = $path;

        for ($layer = 1; ($decoded = rawurldecode($current)) !== $current; $layer++) {
            if ($layer > self::MAX_PATH_DECODE_LAYERS) {
                throw new InvalidArgumentException(
                    'RelayHttpRequest: "path" is percent-encoded too deeply.',
                );
            }
            $layers[] = $decoded;
            $current = $decoded;
        }

        return $layers;
    }

    /**
     * Reject any control byte (ord < 0x20) or DEL (0x7F) in a request-head
     * field. CR or LF in a header or the query would let an untrusted hub
     * producer inject lines into the HTTP head a consumer reconstitutes from
     * this envelope.
     *
     * @param string $value Field value to screen.
     * @param string $label Field name used in the failure message.
     *
     * @throws InvalidArgumentException When the value carries a screened byte.
     */
    private static function assertNoControlCharacters(string $value, string $label): void
    {
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $code = ord($value[$i]);
            if ($code < 0x20 || $code === 0x7F) {
                throw new InvalidArgumentException(
                    'RelayHttpRequest: "' . $label . '" must not contain control characters.',
                );
            }
        }
    }

    /**
     * Whether a header name is a trust-bearing header the consumer must not
     * accept from the untrusted relay producer (see {@see self::STRIPPED_HEADERS}).
     * Case-insensitive, and blind to surrounding SP/HTAB padding: a name
     * like "Authorization " (which lenient downstream parsers trim back to
     * "Authorization") is treated as forbidden too — fail-closed, so the
     * padded spelling is stripped rather than forwarded.
     *
     * @param string $name Header name.
     *
     * @return bool True when the header must be stripped before forwarding.
     *
     * @since 0.11.0
     */
    public static function isForbiddenHeader(string $name): bool
    {
        return in_array(strtolower(trim($name, " \t")), self::STRIPPED_HEADERS, true);
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
