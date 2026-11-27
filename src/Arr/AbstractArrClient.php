<?php

/**
 * Abstract Arr Client.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Arr;

use Phlix\Shared\Arr\Transport\ArrTransportInterface;
use Phlix\Shared\Arr\Transport\CurlArrTransport;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Shared base for the *arr HTTP API clients (Radarr/Sonarr/Prowlarr/Bazarr).
 *
 * Centralises the constructor, header building, the GET/POST/PUT/DELETE request
 * methods, and the per-status-code error mapping that were previously
 * duplicated across each client. Subclasses provide only their
 * endpoint-specific methods plus {@see AbstractArrClient::vendorName()} used in
 * error messages (e.g. "Radarr API error: HTTP 500").
 *
 * All HTTP I/O is delegated to an injected {@see ArrTransportInterface}. When none
 * is supplied the class falls back to the bundled, **blocking** {@see CurlArrTransport}
 * so direct instantiation in CLI scripts/tests keeps working unchanged.
 *
 * RUNTIME TRUTH (phlix-shared-001): an earlier revision of this note claimed
 * phlix-hub keeps this default transport because its resident workers enable
 * `SWOOLE_HOOK_ALL` (curl hooked, so the blocking call yields). That was false
 * at the shipped config: hub's start.php re-applies the curated
 * `SwooleRuntime` hook mask on every worker start, and that mask DROPS the
 * curl hooks — `curl_exec` on hub workers is an ordinary blocking syscall.
 * The hazard of the default transport there is a WORKER STALL of up to the
 * request timeout, per *arr call, several calls deep on the admin approve
 * chain — a tolerated stall, not a coroutine property. Cooperative
 * event-loop consumers (Workerman/Webman) should therefore inject an async,
 * non-blocking transport regardless of platform, which is exactly what
 * phlix-server does. The bundled default additionally serializes on one
 * process-wide handle behind a fail-loud in-flight guard (see
 * {@see CurlArrTransport}). See {@see ArrTransportInterface}.
 *
 * @package Phlix\Shared\Arr
 * @since 0.4.0
 */
abstract class AbstractArrClient
{
    protected string $baseUrl;
    protected string $apiKey;
    protected ?LoggerInterface $logger;
    protected ArrTransportInterface $transport;

    /**
     * Creates a new *arr client.
     *
     * @param string $baseUrl Base URL of the *arr instance (e.g. `http://localhost:7878`).
     * @param string $apiKey  API key for authentication. Must not contain CR
     *     or LF — such a key would inject a malformed X-Api-Key header, so it
     *     is rejected at construction with a RuntimeException.
     * @param LoggerInterface|null $logger Optional logger instance.
     * @param int $timeout Request timeout in seconds (default 30). The value is
     *     forwarded to the default transport only; injected transports own
     *     their own timing (there used to be a write-once `$this->timeout`
     *     copy nothing ever read).
     * @param ArrTransportInterface|null $transport Optional HTTP transport. When null,
     *     the blocking {@see CurlArrTransport} is used — appropriate for CLI/tests;
     *     on a resident event-loop worker it stalls that worker for the duration of
     *     every call (see the RUNTIME TRUTH note in the class docblock), so such
     *     consumers should inject an async, non-blocking transport. Building the
     *     default requires ext-curl; a runtime without it fails loud at construction.
     * @param int $connectTimeout Connect-phase timeout in seconds (phlix-shared-032):
     *     bounds how long the TCP+TLS handshake alone may stall, independent of the
     *     overall $timeout. Forwarded to the default transport only; injected
     *     transports own their own timing.
     */
    public function __construct(
        string $baseUrl,
        string $apiKey,
        ?LoggerInterface $logger = null,
        int $timeout = 30,
        ?ArrTransportInterface $transport = null,
        int $connectTimeout = 5
    ) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        if ($scheme === null || ($scheme !== 'http' && $scheme !== 'https')) {
            throw new RuntimeException(
                'Invalid baseUrl scheme "' . (is_string($scheme) ? $scheme : 'null') . '": must be http or https'
            );
        }

        // The X-Api-Key header is assembled by raw concatenation, so a key
        // carrying a line break would reach the transport as a malformed —
        // or header-injecting — request line. Fail at this seam, naming it,
        // instead of letting curl notice downstream.
        if (str_contains($apiKey, "\r") || str_contains($apiKey, "\n")) {
            throw new RuntimeException(
                'API key must not contain CR or LF — it would inject a malformed X-Api-Key header.'
            );
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->logger = $logger;
        $this->transport = $transport ?? self::defaultTransport($timeout, $connectTimeout);

        // The constructor runs exactly once per instance, so no "logged"
        // flag is needed: one HTTP baseUrl means exactly one warning.
        if ($scheme === 'http') {
            $this->logger?->warning(
                $this->vendorName() . ' baseUrl uses HTTP — X-Api-Key is sent in clear text'
            );
        }
    }

    /**
     * Capability probe for the bundled default transport.
     *
     * ext-curl is only a require-dev/suggest of this package, so a runtime
     * without it used to die on an undefined-function error inside the
     * transport on the first default-transport construction. Overridable seam
     * so tests can exercise the fail-loud arm without uninstalling curl.
     */
    protected static function hasCurlSupport(): bool
    {
        return function_exists('curl_init');
    }

    /**
     * Builds the bundled default transport, failing loud — with a name — when
     * the runtime cannot support it. Injection is the documented escape hatch:
     * a consumer without curl injects its own {@see ArrTransportInterface}.
     *
     * @param int $timeout Request timeout in seconds, forwarded to the default.
     * @param int $connectTimeout Connect-phase timeout in seconds, forwarded to
     *     the default (phlix-shared-032) — until now the only timeout the
     *     client ever passed was the overall one, leaving the connect phase at
     *     whatever the transport defaulted to.
     */
    private static function defaultTransport(int $timeout, int $connectTimeout): ArrTransportInterface
    {
        if (!static::hasCurlSupport()) {
            throw new RuntimeException(
                'The bundled default *arr transport requires the PHP curl extension (ext-curl): '
                . 'install it or inject an ArrTransportInterface implementation.'
            );
        }

        return new CurlArrTransport($timeout, $connectTimeout);
    }

    /**
     * Vendor name used in error messages (e.g. "Radarr", "Sonarr").
     */
    abstract protected function vendorName(): string;

    /**
     * Performs a GET request.
     *
     * @param string $path Request path.
     * @return array<array-key, mixed> Decoded JSON response: object shapes
     *     carry string keys, list endpoints carry sequential int keys; values
     *     stay mixed because json_decode can produce any JSON type. Concrete
     *     clients declare the narrowed endpoint shape on their public methods
     *     (e.g. {@see RadarrClient::getMovies()}).
     * @throws RuntimeException On network or HTTP errors.
     */
    protected function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /**
     * Performs a POST request with a JSON body.
     *
     * @param string $path Request path.
     * @param array<string, mixed> $body JSON-serializable body.
     * @return array<array-key, mixed> Decoded JSON response — see
     *     {@see self::get()} for the key/value law.
     * @throws RuntimeException On network or HTTP errors.
     */
    protected function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * Performs a PUT request with a JSON body.
     *
     * @param string $path Request path.
     * @param array<string, mixed> $body JSON-serializable body.
     * @return array<array-key, mixed> Decoded JSON response — see
     *     {@see self::get()} for the key/value law.
     * @throws RuntimeException On network or HTTP errors.
     */
    protected function put(string $path, array $body): array
    {
        return $this->request('PUT', $path, $body);
    }

    /**
     * Performs a DELETE request.
     *
     * @param string $path Request path.
     * @return array<array-key, mixed> Decoded JSON response — see
     *     {@see self::get()} for the key/value law.
     * @throws RuntimeException On network or HTTP errors.
     */
    protected function delete(string $path): array
    {
        return $this->request('DELETE', $path, null);
    }

    /**
     * Executes an HTTP request against the *arr instance and decodes the JSON body.
     *
     * The wire I/O is delegated to the injected {@see ArrTransportInterface}; this
     * method only builds the request, maps status codes to exceptions, and decodes
     * the JSON body. No cURL call happens here when a non-default transport is injected.
     *
     * @param string $method One of GET/POST/PUT/DELETE.
     * @param string $path Request path.
     * @param array<string, mixed>|null $body JSON-serializable body for POST/PUT; null otherwise.
     * @return array<array-key, mixed> Decoded JSON response: object shapes carry
     *     string keys, list endpoints carry sequential int keys, and values stay
     *     mixed because json_decode can produce any JSON type. This is the honest
     *     floor of the seam: concrete clients declare the
     *     narrowed per-endpoint shape on their public methods, and consumers must
     *     treat this level as untyped until they do.
     * @throws RuntimeException On network or HTTP errors.
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $url = $this->baseUrl . $path;

        $encodedBody = null;
        if ($method === 'POST' || $method === 'PUT') {
            $encodedBody = $this->encodeBody($body ?? []);
        }

        $response = $this->transport->request($method, $url, $this->buildHeaders(), $encodedBody);
        $httpCode = $response['status'];
        $responseBody = $response['body'];

        $vendor = $this->vendorName();

        if ($httpCode === 401) {
            throw new RuntimeException($vendor . ' API authentication failed (401)');
        }

        if ($httpCode === 404) {
            throw new RuntimeException($vendor . ' API resource not found (404): ' . $path);
        }

        if ($httpCode >= 400) {
            // The *arr APIs carry the actionable message in the 4xx/5xx
            // JSON body — dropping it left callers with "HTTP 500" and
            // nothing to act on. Append a capped, single-line excerpt.
            // Redact BEFORE trimming/capping:
            // the vendor body can echo the caller's API key back, and only
            // two client methods re-wrapped errors with SecretRedactor —
            // every other method raised this raw message, keys included,
            // and a key cut mid-value by the cap would still have leaked
            // its head.
            throw new RuntimeException(
                $vendor . ' API error: HTTP ' . $httpCode
                . $this->detailFromBody(SecretRedactor::redact($responseBody, $this->apiKey))
            );
        }

        // No transport follows redirects — CurlArrTransport pins
        // CURLOPT_FOLLOWLOCATION => false (explicitly, not by inherited
        // libcurl default) and the law is stated on ArrTransportInterface for
        // injected implementations (phlix-shared-027). A 3xx is never a
        // success: a 301
        // with an empty body used to fall through to the empty-body return
        // and decode as "no data" — surface it as the error it is.
        if ($httpCode >= 300) {
            throw new RuntimeException(
                $vendor . ' API redirect not followed (HTTP ' . $httpCode . '): ' . $path
            );
        }

        if ($responseBody === '') {
            return [];
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from ' . $vendor);
        }

        return $decoded;
    }

    /**
     * Compact single-line excerpt of an error response body for messages.
     *
     * Capped at 300 bytes with whitespace collapsed so a full HTML error
     * page can never bloat a log line; an empty body contributes nothing.
     */
    private function detailFromBody(string $body): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '';
        }

        $oneLine = (string) preg_replace('/\s+/', ' ', $trimmed);
        if (strlen($oneLine) > 300) {
            $oneLine = substr($oneLine, 0, 300) . '...';
        }

        return ' — ' . $oneLine;
    }

    /**
     * JSON-encodes a request body, throwing on failure.
     *
     * @param array<string, mixed> $body JSON-serializable body.
     * @throws RuntimeException When encoding fails.
     */
    private function encodeBody(array $body): string
    {
        $encoded = json_encode($body);

        if ($encoded === false) {
            throw new RuntimeException('json_encode failed for ' . $this->vendorName() . ' request body');
        }

        return $encoded;
    }

    /**
     * Builds the HTTP headers for *arr API requests.
     *
     * @return array<string> Headers array.
     */
    private function buildHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Api-Key: ' . $this->apiKey,
        ];
    }
}
