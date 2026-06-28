<?php

declare(strict_types=1);

namespace Phlix\Shared\Arr\Transport;

use RuntimeException;

/**
 * Default, **blocking** cURL transport for the *arr API clients.
 *
 * This is the implementation {@see \Phlix\Shared\Arr\AbstractArrClient} falls back
 * to when no transport is injected, preserving the original synchronous behaviour
 * for direct instantiation in CLI scripts and tests.
 *
 * WARNING — this transport calls `curl_exec()` synchronously and therefore BLOCKS.
 * It is **for CLI/test use only**. Inside an event loop (Workerman/Webman) a slow
 * *arr instance would stall every coroutine on the worker; such consumers MUST
 * inject an async, non-blocking {@see ArrTransportInterface} implementation instead
 * (e.g. a `workerman/http-client`-backed transport in the consumer). Keeping this
 * blocking call behind the injected seam is what lets the shared library honour its
 * "zero I/O" charter.
 *
 * @package Phlix\Shared\Arr\Transport
 * @since 0.11.0
 */
final class CurlArrTransport implements ArrTransportInterface
{
    /**
     * @param int $timeout Overall request timeout in seconds.
     */
    public function __construct(private readonly int $timeout = 30)
    {
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string> $headers
     * @return array{status:int, body:string}
     * @throws RuntimeException On a transport-level cURL failure.
     */
    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        if ($url === '') {
            throw new RuntimeException('CurlArrTransport: empty request URL');
        }
        if ($method === '') {
            throw new RuntimeException('CurlArrTransport: empty request method');
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = (string) $body;
        } elseif ($method === 'PUT') {
            $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $options[CURLOPT_POSTFIELDS] = (string) $body;
        } elseif ($method === 'DELETE') {
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null && $body !== '') {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('curl_init() failed');
        }

        curl_setopt_array($ch, $options);

        /** @var string|false $responseBody */
        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlErrno !== 0) {
            throw new RuntimeException('cURL error: ' . $curlError, $curlErrno);
        }

        return ['status' => $httpCode, 'body' => $responseBody];
    }
}
