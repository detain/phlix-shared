<?php

/**
 * Curl Arr Transport.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Arr\Transport;

use InvalidArgumentException;
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
 * *arr instance stalls the worker for the duration of the request; such consumers
 * MUST inject an async, non-blocking {@see ArrTransportInterface} implementation
 * instead (e.g. a `workerman/http-client`-backed transport in the consumer).
 * Keeping this blocking call behind the injected seam is what lets the shared
 * library honour its "zero I/O" charter.
 *
 * SHARED-HANDLE LAW — every instance's transfer runs on ONE process-wide
 * `private static ?\CurlHandle` (`self::$handle`), created lazily and
 * serialized between calls by `curl_reset()` at the start of each `request()`.
 * The handle is never `curl_close()`d: keeping it for the process lifetime is
 * the point (connection reuse is the only benefit this class offers), and it is
 * reclaimed at process exit. Consequences stated plainly: two instances are
 * two sets of options, not two sockets.
 *
 * RUNTIME TRUTH (phlix-shared-001) — an earlier revision of this law framed
 * the sharing hazard as coroutine interleaving across transfers on the one
 * handle under a curl-hooked runtime, with phlix-hub keeping this transport
 * "safe" behind its S425 position. That premise was false at the shipped
 * config: phlix-hub's start.php re-applies the curated `SwooleRuntime` hook
 * mask on every worker start, and that mask DROPS the curl hooks — there
 * `curl_exec` is an ordinary blocking syscall that stalls the worker (the
 * hazard named above), never a coroutine yield. Interleaving is not live
 * today; it is latent for an operator who points `coroutine.hook_flags`
 * toward `SWOOLE_HOOK_ALL`. To make that latent path fail loud instead of
 * corrupting, `request()` carries an IN-FLIGHT GUARD: while a transfer owns
 * the shared handle, starting a second one throws a RuntimeException naming
 * this law (a hooked `curl_exec` yields at I/O points, so overlap would mix
 * bodies, headers and write sinks mid-transfer). The guard is released in a
 * `finally`, so a failed or thrown transfer can never wedge the transport.
 * Making the handle per-instance would trade away the connection reuse
 * consumer-visibly, so the shared shape is kept, the in-flight law is
 * enforced at runtime, and both are pinned in `CurlArrTransportTest`.
 *
 * RESPONSE CAP (phlix-shared-032) — every transfer installs a
 * `CURLOPT_WRITEFUNCTION` sink that accepts chunks only up to
 * `$maxResponseBytes` and aborts (return 0 → CURLE_WRITE_ERROR) on the first
 * byte beyond it, so a runaway *arr response cannot grow the worker's memory
 * without bound. The default matches the house cap of
 * {@see \Phlix\Shared\Arr\TrashGuidesProvider} (32 MiB).
 *
 * @package Phlix\Shared\Arr\Transport
 * @since 0.11.0
 */
final class CurlArrTransport implements ArrTransportInterface
{
    /**
     * Default response cap: 32 MiB — the same house value used by
     * {@see \Phlix\Shared\Arr\TrashGuidesProvider::MAX_RESPONSE_BYTES}.
     */
    public const DEFAULT_MAX_RESPONSE_BYTES = 33_554_432;

    /** @var \CurlHandle|null Reusable cURL handle (PHP 8.0+) — process-wide by
     *    design: see the SHARED-HANDLE LAW in the class docblock. */
    private static ?\CurlHandle $handle = null;

    /** @var bool True while a transfer owns the shared handle — the in-flight
     *    law of the SHARED-HANDLE LAW (phlix-shared-001). */
    private static bool $inFlight = false;

    /** @var int Bytes accepted by the current transfer's capped sink. */
    private int $receivedBytes = 0;

    /** @var string Body collected by the current transfer's capped sink. */
    private string $responseBody = '';

    /** @var bool Set when the sink refused a chunk — the response cap was hit. */
    private bool $responseOverflow = false;

    /**
     * @param int $timeout Overall request timeout in seconds.
     * @param int $connectTimeout Connect-phase timeout in seconds: bounds how
     *     long the TCP+TLS handshake alone may stall, independent of $timeout.
     * @param int $maxResponseBytes Hard cap on collected response bytes; the
     *     transfer is aborted mid-body once this many bytes are exceeded
     *     (phlix-shared-032).
     * @throws InvalidArgumentException When $maxResponseBytes is not positive.
     */
    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 5,
        private readonly int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES
    ) {
        if ($maxResponseBytes < 1) {
            throw new InvalidArgumentException(
                'CurlArrTransport: maxResponseBytes must be positive, got ' . $maxResponseBytes
            );
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string> $headers
     * @return array{status:int, body:string}
     * @throws RuntimeException On a transport-level cURL failure, when the
     *     response exceeds the configured byte cap, or when another transfer
     *     is already in flight on the shared handle.
     */
    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        if ($url === '') {
            throw new RuntimeException('CurlArrTransport: empty request URL');
        }
        if ($method === '') {
            throw new RuntimeException('CurlArrTransport: empty request method');
        }

        // In-flight guard (phlix-shared-001): checked BEFORE any handle touch,
        // so a refused overlap neither initializes nor mutates the shared
        // state of the transfer already running on it.
        if (self::$inFlight) {
            throw new RuntimeException(
                'CurlArrTransport: refusing to start a transfer — one is already in flight on the '
                . 'process-wide shared handle (see the SHARED-HANDLE LAW). Overlap would corrupt '
                . 'the live transfer on a curl-hooked runtime; inject an async transport instead.'
            );
        }
        self::$inFlight = true;

        try {
            $this->receivedBytes = 0;
            $this->responseBody = '';
            $this->responseOverflow = false;

            $options = $this->buildOptions($method, $url, $headers, $body);

            if (self::$handle === null) {
                $handle = curl_init();
                if ($handle === false) {
                    throw new RuntimeException('curl_init() failed');
                }
                self::$handle = $handle;
            } else {
                curl_reset(self::$handle);
            }

            $ch = self::$handle;
            curl_setopt_array($ch, $options);

            // With CURLOPT_WRITEFUNCTION installed the body flows through the
            // capped sink; curl_exec's return value only signals success
            // (false = transport failure), so it is never read as the body.
            $execResult = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            $curlError = curl_error($ch);

            if ($this->responseOverflowed()) {
                throw new RuntimeException(
                    'cURL response exceeded the ' . $this->maxResponseBytes . '-byte cap'
                );
            }

            if ($execResult === false || $curlErrno !== 0) {
                throw new RuntimeException('cURL error: ' . $curlError, $curlErrno);
            }

            return ['status' => $httpCode, 'body' => $this->responseBody];
        } finally {
            self::$inFlight = false;
        }
    }

    /**
     * Did the capped sink abort the transfer?
     *
     * The flag is set by {@see writeChunk()} — a callback libcurl invokes
     * from inside curl_exec(), which no analyzer models as reachable from
     * this control flow. A direct property read here gets narrowed to the
     * literal `false` of the reset above (phpstan if.alwaysFalse / psalm
     * TypeDoesNotContainType), so the read crosses a method boundary whose
     * declared return type keeps it honestly bool.
     */
    private function responseOverflowed(): bool
    {
        return $this->responseOverflow;
    }

    /**
     * Capped sink for CURLOPT_WRITEFUNCTION (phlix-shared-032).
     *
     * Accepts chunks while the collected total stays within
     * $maxResponseBytes; on the chunk that would cross the cap it flags the
     * overflow and returns 0, making libcurl abort the transfer with
     * CURLE_WRITE_ERROR. Counting bytes actually received — a lying
     * Content-Length cannot defeat it (a header-based CURLOPT_MAXFILESIZE
     * trusts the server).
     *
     * @param mixed $handle The cURL handle passed by the extension (unused).
     * @param string $chunk Data received in this callback.
     * @return int Bytes accepted; 0 aborts the transfer.
     */
    private function writeChunk(mixed $handle, string $chunk): int
    {
        $size = strlen($chunk);
        if ($this->receivedBytes + $size > $this->maxResponseBytes) {
            $this->responseOverflow = true;

            return 0;
        }

        $this->receivedBytes += $size;
        $this->responseBody .= $chunk;

        return $size;
    }

    /**
     * Builds the full cURL option set for one transfer.
     *
     * Applied to the shared handle right after `curl_reset()`, so every
     * security-relevant option (protocol pin, TLS verification, redirect
     * ban, capped sink) is restated on each request rather than inherited
     * from a previous transfer or from libcurl/php.ini defaults.
     *
     * @param array<string> $headers
     * @return array<int, mixed>
     */
    private function buildOptions(string $method, string $url, array $headers, ?string $body): array
    {
        $options = [
            CURLOPT_URL => $url,
            // Belt only: the body is collected by WRITEFUNCTION below. The
            // flag stays set so curl_exec's success value can never be a
            // silently-dropped unbounded buffer.
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HTTPHEADER => $headers,
            // Pin to HTTP/HTTPS only — reject file://, gopher://, ftp:// etc.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // Redirect law (phlix-shared-027): never follow redirects, pinned
            // explicitly per this class's restated-every-option doctrine
            // rather than inherited from libcurl's default 0 — following a
            // 3xx would resend X-Api-Key to a host the *arr server (or an
            // attacker with a stored redirect) chose. MAXREDIRS 0 is the
            // second lock; REDIR_PROTOCOLS stays pinned in case a future
            // change ever re-enables following.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // TLS verification stated explicitly rather than inherited from
            // libcurl defaults: the request carries the *arr API key, so a
            // build or php.ini that relaxed verification must not silently
            // downgrade this transport. VERIFYHOST 2 = the name must match.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Capped response sink (phlix-shared-032): body arrives through
            // writeChunk(), bounded by $maxResponseBytes there.
            CURLOPT_WRITEFUNCTION => \Closure::fromCallable([$this, 'writeChunk']),
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

        return $options;
    }
}
