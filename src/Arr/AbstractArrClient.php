<?php

declare(strict_types=1);

namespace Phlix\Shared\Arr;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Shared base for the *arr HTTP API clients (Radarr/Sonarr/Prowlarr/Bazarr).
 *
 * Centralises the constructor, header building, the GET/POST/PUT/DELETE cURL
 * methods, and the per-status-code error mapping that were previously
 * duplicated across each client. Subclasses provide only their
 * endpoint-specific methods plus {@see AbstractArrClient::vendorName()} used in
 * error messages (e.g. "Radarr API error: HTTP 500").
 *
 * NOTE: This is intentionally still blocking cURL. A later step (F2b) swaps the
 * transport behind an injected seam; this class only removes the duplication so
 * that swap happens in one place.
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

    /**
     * Creates a new *arr client.
     *
     * @param string $baseUrl Base URL of the *arr instance (e.g. `http://localhost:7878`).
     * @param string $apiKey  API key for authentication.
     * @param LoggerInterface|null $logger Optional logger instance.
     * @param int $timeout Request timeout in seconds (default 30).
     */
    public function __construct(
        string $baseUrl,
        string $apiKey,
        ?LoggerInterface $logger = null,
        int $timeout = 30
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->logger = $logger;
        $this->timeout = $timeout;
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
     * @param string $method One of GET/POST/PUT/DELETE.
     * @param string $path Request path.
     * @param array<string, mixed>|null $body JSON-serializable body for POST/PUT; null otherwise.
     * @return array<mixed, mixed> Decoded JSON response.
     * @throws RuntimeException On network or HTTP errors.
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $url = $this->baseUrl . $path;
        assert($url !== '');

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $this->encodeBody($body ?? []);
        } elseif ($method === 'PUT') {
            $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $options[CURLOPT_POSTFIELDS] = $this->encodeBody($body ?? []);
        } elseif ($method === 'DELETE') {
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('curl_init() failed');
        }

        curl_setopt_array($ch, $options);

        /** @var string|false */
        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlErrno !== 0) {
            throw new RuntimeException('cURL error: ' . $curlError, $curlErrno);
        }

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
