<?php

/**
 * Arr Transport Interface.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Arr\Transport;

/**
 * Transport seam for the *arr HTTP API clients.
 *
 * {@see \Phlix\Shared\Arr\AbstractArrClient} performs all of its HTTP I/O through
 * this interface, so the actual wire mechanism is a pluggable, injectable concern
 * rather than a hard-coded blocking call. This keeps the package honest about its
 * "zero I/O" charter: the only bundled implementation that touches the network is
 * {@see CurlArrTransport}, which is intended for CLI/test usage only.
 *
 * **Event-loop consumers (Workerman/Webman) MUST inject an async, non-blocking
 * transport** (e.g. a `workerman/http-client`-backed implementation living in the
 * consumer) so that a slow *arr instance never stalls the worker's coroutines.
 *
 * Implementations MUST NOT throw on a non-2xx HTTP status — they return the status
 * and raw body and let the caller map it. They MAY throw on a genuine transport
 * failure (DNS, connection refused, timeout).
 *
 * @package Phlix\Shared\Arr\Transport
 * @since 0.11.0
 */
interface ArrTransportInterface
{
    /**
     * Executes a single HTTP request and returns the status code and raw body.
     *
     * @param string $method One of GET/HEAD/POST/PUT/PATCH/DELETE/OPTIONS (upper-cased).
     * @param string $url    Absolute request URL.
     * @param array<string> $headers Headers as raw `Name: value` strings.
     * @param string|null $body Raw request body for write methods; null for none.
     * @return array{status:int, body:string} HTTP status code and raw response body.
     */
    public function request(string $method, string $url, array $headers, ?string $body): array;
}
