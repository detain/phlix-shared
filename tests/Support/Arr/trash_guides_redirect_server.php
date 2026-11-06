<?php

/**
 * A throwaway loopback TLS server speaking fixed HTTP/1.1 responses — the
 * counterpart of the redirect-walk E2E in tests/Arr/TrashGuidesProviderTest.php
 * (LW-061 minor cure). Started with proc_open([PHP_BINARY, __FILE__, ...]);
 * no class, no autoload, no dependency on anything but ext-openssl streams.
 *
 * argv: <port> <scriptJsonPath> <servedLogPath> <serverPemPath> <maxConns>
 *
 * Script shape (written by the parent test):
 *   {"routes": {"/h0.json": {"status": 301, "location": "https://..."},
 *               "/h6.json": {"status": 200, "body": "..."}, ...}}
 * Any path absent from `routes` answers a final 404 so an over-dialing
 * mutant can never wedge this child waiting on a missing script entry.
 *
 * Every connection is logged to the served-log BEFORE its response is
 * written, so "the parent observed a SERVED line" is a strict witness that
 * fetchUrl reached that dial — and its absence, once fetchUrl has returned
 * or thrown, is a strict witness that the hop was refused/budgeted BEFORE
 * dialing. A non-TLS connection (e.g. a mutant dialing plain http:// at
 * this port) logs CRYPTOFAIL and is closed unanswered.
 *
 * The child exits after maxConns connections, after a 10 s idle accept, or
 * at a 30 s hard wall — a parent that dies mid-fetch must never leave a TLS
 * listener bound for the rest of CI's life (the parent's finally block also
 * proc_terminate()s).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

$port = (int) ($argv[1] ?? 0);
$scriptPath = (string) ($argv[2] ?? '');
$servedLogPath = (string) ($argv[3] ?? '');
$serverPemPath = (string) ($argv[4] ?? '');
$maxConns = (int) ($argv[5] ?? 8);

if ($port <= 0 || $scriptPath === '' || $servedLogPath === '' || $serverPemPath === '') {
    fwrite(STDERR, "usage: trash_guides_redirect_server.php <port> <scriptJson> <servedLog> <serverPem> [maxConns]\n");
    exit(1);
}

/** @var array{routes?: array<string, array{status?: int, location?: string, body?: string}>} $script */
$decoded = json_decode((string) file_get_contents($scriptPath), true);
$script = is_array($decoded) ? $decoded : [];
$routes = isset($script['routes']) && is_array($script['routes']) ? $script['routes'] : [];

$listenerContext = stream_context_create(['ssl' => ['local_cert' => $serverPemPath]]);
$listener = @stream_socket_server(
    'tcp://127.0.0.1:' . $port,
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $listenerContext
);
if ($listener === false) {
    fwrite(STDERR, "bind fail $errno $errstr\n");
    exit(1);
}

fwrite(STDOUT, "READY\n");

$deadline = time() + 30;
$served = 0;

while ($served < $maxConns && time() < $deadline) {
    $connection = @stream_socket_accept($listener, 10);
    if ($connection === false) {
        break;
    }

    $secured = @stream_socket_enable_crypto(
        $connection,
        true,
        STREAM_CRYPTO_METHOD_TLS_SERVER
    );
    if (!$secured) {
        file_put_contents($servedLogPath, "CRYPTOFAIL\n", FILE_APPEND);
        fclose($connection);
        $served++;
        continue;
    }

    $requestLine = fgets($connection, 1024);
    if ($requestLine === false) {
        fclose($connection);
        $served++;
        continue;
    }

    $path = preg_match('#^GET (\S+) HTTP/#', $requestLine, $match) === 1 ? $match[1] : '?';

    // Drain the request headers so the client sees a well-formed exchange.
    while (($headerLine = fgets($connection, 1024)) !== false && $headerLine !== "\r\n") {
        // no-op: the response is scripted by path, headers are irrelevant
    }

    /** @var array{status?: int, location?: string, body?: string} $route */
    $route = $routes[$path] ?? ['status' => 404, 'body' => '{"error":"not-scripted"}'];
    $status = (int) ($route['status'] ?? 200);

    if ($status >= 300 && $status < 400 && $status !== 304 && isset($route['location'])) {
        $body = '';
        $response = "HTTP/1.1 $status Redirect\r\nLocation: " . $route['location']
            . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    } else {
        $body = (string) ($route['body'] ?? '{"ok":true}');
        $response = "HTTP/1.1 $status Final\r\nContent-Type: application/json"
            . "\r\nContent-Length: " . strlen($body)
            . "\r\nConnection: close\r\n\r\n" . $body;
    }

    // Log BEFORE answering: the SERVED line is written to disk before the
    // response bytes can reach the client, closing the return-then-read race.
    file_put_contents($servedLogPath, "SERVED $path $status\n", FILE_APPEND);
    fwrite($connection, $response);
    fclose($connection);
    $served++;
}

fclose($listener);
exit(0);
