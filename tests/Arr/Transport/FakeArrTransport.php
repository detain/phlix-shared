<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Arr\Transport;

use Phlix\Shared\Arr\Transport\ArrTransportInterface;

/**
 * Deterministic, in-memory {@see ArrTransportInterface} for tests.
 *
 * Records every request it receives and returns a canned `{status, body}` response,
 * never touching the network or cURL. This makes the *arr client tests fully
 * deterministic (closes CQ5) and lets a test assert that, once injected, the client
 * performs all I/O through the seam (no `curl_exec`).
 *
 * @package Phlix\Shared\Tests\Arr\Transport
 */
final class FakeArrTransport implements ArrTransportInterface
{
    /** @var list<array{method:string, url:string, headers:array<int, string>, body:string|null}> */
    public array $calls = [];

    /**
     * @param int $status Canned HTTP status to return.
     * @param string $body Canned response body to return.
     */
    public function __construct(
        private readonly int $status = 200,
        private readonly string $body = '[]'
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int, string> $headers
     * @return array{status:int, body:string}
     */
    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => array_values($headers),
            'body' => $body,
        ];

        return ['status' => $this->status, 'body' => $this->body];
    }
}
