<?php

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
 * so direct instantiation in CLI scripts/tests keeps working unchanged. Event-loop
 * consumers (Workerman/Webman) MUST inject an async, non-blocking transport so a slow
 * *arr instance never stalls the worker — see {@see ArrTransportInterface}.
 *
 * @package Phlix\Shared\Arr
 * @since 0.4.0
 */
abstract class AbstractArrClient
{
    protected string $baseUrl;
    protected string $apiKey;
    protected ?LoggerInterface $logger;
    protected int $timeout;
    protected ArrTransportInterface $transport;

    /**
     * Creates a new *arr client.
     *
     * @param string $baseUrl Base URL of the *arr instance (e.g. `http://localhost:7878`).
     * @param string $apiKey  API key for authentication.
     * @param LoggerInterface|null $logger Optional logger instance.
     * @param int $timeout Request timeout in seconds (default 30).
     * @param ArrTransportInterface|null $transport Optional HTTP transport. When null,
     *     a blocking {@see CurlArrTransport} (CLI/test only) is used. Event-loop
     *     consumers MUST inject an async, non-blocking transport.
     */
    public function __construct(
        string $baseUrl,
        string $apiKey,
        ?LoggerInterface $logger = null,
        int $timeout = 30,
        ?ArrTransportInterface $transport = null
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->logger = $logger;
        $this->timeout = $timeout;
        $this->transport = $transport ?? new CurlArrTransport($timeout);
    }

    /**
     * Vendor name used in error messages (e.g. "Radarr", "Sonarr").
     */
    abstract protected function vendorName(): string;

    /**
     * Performs a GET request.
     *
     * @param string $path Request path.
     * @return array<mixed, mixed> Decoded JSON response.
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
     * @return array<mixed, mixed> Decoded JSON response.
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
     * @return array<mixed, mixed> Decoded JSON response.
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
     * @return array<mixed, mixed> Decoded JSON response.
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
     * @return array<mixed, mixed> Decoded JSON response.
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
            throw new RuntimeException($vendor . ' API error: HTTP ' . $httpCode);
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
