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
 * "zero I/O" charter: network code is confined to the `Arr/` exception — within
 * this seam the only bundled implementation is {@see CurlArrTransport}, intended
 * for CLI/test use — and, stated plainly, `TrashGuidesProvider` performs
 * its own blocking stream fetches that do NOT route through this interface (no
 * transport seam there today; see that class's documented limitations).
 *
 * **Event-loop consumers (Workerman/Webman) should inject an async, non-blocking
 * transport** (e.g. a `workerman/http-client`-backed implementation living in the
 * consumer) so that a slow *arr instance never stalls the worker. An earlier
 * revision of this note pointed at phlix-hub's runtime curl hooks as the case
 * for keeping the blocking default safely; that premise was false at the
 * shipped config (phlix-shared-001): hub's worker-start re-applies the curated
 * `SwooleRuntime` hook mask, which drops the curl hooks — on hub the default
 * transport stalls the worker plainly, and the stall is tolerated rather than
 * yielded away. Consumers on any resident loop therefore own the decision to
 * block.
 *
 * **Redirect law (phlix-shared-027):** implementations MUST NOT follow
 * redirects. The *arr clients authenticate with an `X-Api-Key` header, and a
 * transparently followed 3xx would resend that key to whatever host the
 * response names — a destination outside the operator's control. A 3xx is
 * returned to the caller exactly as received (status and body); its body is
 * never consumed as success data, because the client maps 3xx to an error
 * before decoding (see {@see \Phlix\Shared\Arr\AbstractArrClient}).
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
