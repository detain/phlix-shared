<?php

/**
 * Curl Arr Transport Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Arr\Transport;

use PHPUnit\Framework\TestCase;
use Phlix\Shared\Arr\AbstractArrClient;
use Phlix\Shared\Arr\Transport\ArrTransportInterface;
use Phlix\Shared\Arr\Transport\CurlArrTransport;
use RuntimeException;

/**
 * Unit tests for CurlArrTransport.
 *
 * Tests protocol pinning (CURLOPT_PROTOCOLS, CURLOPT_REDIR_PROTOCOLS), explicit
 * TLS verification (CURLOPT_SSL_VERIFYPEER/VERIFYHOST) and connect timeout
 * (CURLOPT_CONNECTTIMEOUT) are properly configured, alongside the SHARED-HANDLE
 * LAW family: the in-flight guard (phlix-shared-001), the explicit redirect ban
 * and the capped response sink (phlix-shared-027 / phlix-shared-032), plus an
 * anti-rot pin that the disproven "runtime hooks curl" docblock premise cannot
 * creep back into the three Arr classes.
 *
 * The cap family carries an end-to-end loopback pin besides the reflection
 * pins: a real over-cap response must surface the DEDICATED cap-naming error
 * from request() itself, not the generic cURL-error branch (S-shared-01 r1
 * Minor cure — the out-of-band "loopback php -S harness" mutation proof in the
 * commit body becomes a committed, re-runnable conviction here).
 *
 * @package Phlix\Shared\Tests\Arr\Transport
 * @since 0.11.0
 */
class CurlArrTransportTest extends TestCase
{
    /**
     * Source of the throwaway loopback HTTP child used by the end-to-end cap
     * pin below. Written to a private scratch file at runtime (never
     * committed) and started with proc_open([PHP_BINARY, script, port,
     * witness]); the test's finally block terminates it on every exit path.
     * Lifecycle mirrors tests/Support/Arr/trash_guides_redirect_server.php:
     * bind the reserved 127.0.0.1 port, report READY on stdout, answer exactly
     * one request with a fixed 2048-byte body — strictly more than the 64-byte
     * cap the test installs — log the SERVED witness to disk BEFORE the
     * response bytes, then exit; idle accept of 5 s and a 20 s hard wall keep
     * an orphan impossible if the parent dies mid-transfer.
     */
    private const OVER_CAP_SERVER_SCRIPT = <<<'PHP'
<?php
declare(strict_types=1);

$port = (int) ($argv[1] ?? 0);
$witnessPath = (string) ($argv[2] ?? '');
if ($port <= 0 || $witnessPath === '') {
    fwrite(STDERR, "usage: <port> <witnessPath>\n");
    exit(1);
}

$listener = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($listener === false) {
    fwrite(STDERR, "bind fail $errno $errstr\n");
    exit(1);
}
fwrite(STDOUT, "READY\n");

$deadline = time() + 20;
while (time() < $deadline) {
    $connection = @stream_socket_accept($listener, 5);
    if ($connection === false) {
        break;
    }

    // Drain the request headers so the client sees a well-formed exchange.
    while (($headerLine = fgets($connection, 1024)) !== false && $headerLine !== "\r\n") {
        // no-op: the response is fixed; request headers are irrelevant
    }

    $body = str_repeat('A', 2048);
    $response = "HTTP/1.1 200 OK\r\n"
        . "Content-Type: application/json\r\n"
        . "Content-Length: " . strlen($body) . "\r\n"
        . "Connection: close\r\n"
        . "\r\n" . $body;

    // Log BEFORE answering: the SERVED line exists on disk before the
    // response bytes can reach the client (precedent witness law).
    file_put_contents($witnessPath, "SERVED\n", FILE_APPEND);
    fwrite($connection, $response);
    fclose($connection);
    break;
}

fclose($listener);
exit(0);
PHP;

    /**
     * Tests that the transport rejects file:// URLs via protocol pinning.
     * cURL should fail because CURLOPT_PROTOCOLS is set to HTTP|HTTPS only.
     */
    public function testRejectsFileProtocol(): void
    {
        $transport = new CurlArrTransport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cURL error|Protocol .* not supported/i');

        $transport->request('GET', 'file:///etc/passwd', [], null);
    }

    /**
     * Tests that the transport rejects gopher:// URLs via protocol pinning.
     */
    public function testRejectsGopherProtocol(): void
    {
        $transport = new CurlArrTransport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cURL error|Protocol .* not supported/i');

        $transport->request('GET', 'gopher://example.com/', [], null);
    }

    /**
     * Tests that the transport rejects ftp:// URLs via protocol pinning.
     */
    public function testRejectsFtpProtocol(): void
    {
        $transport = new CurlArrTransport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cURL error|Protocol .* not supported/i');

        $transport->request('GET', 'ftp://example.com/file.txt', [], null);
    }

    /**
     * Verifies CURLOPT_CONNECTTIMEOUT is set to the expected default (5s).
     * Uses reflection to inspect the private property.
     */
    public function testDefaultConnectTimeoutIsSet(): void
    {
        $transport = new CurlArrTransport(30, 5);

        $reflection = new \ReflectionClass($transport);
        $prop = $reflection->getProperty('connectTimeout');
        $prop->setAccessible(true);

        $this->assertSame(5, $prop->getValue($transport));
    }

    /**
     * Verifies custom connect timeout is respected.
     */
    public function testCustomConnectTimeoutIsRespected(): void
    {
        $transport = new CurlArrTransport(30, 10);

        $reflection = new \ReflectionClass($transport);
        $prop = $reflection->getProperty('connectTimeout');
        $prop->setAccessible(true);

        $this->assertSame(10, $prop->getValue($transport));
    }

    /**
     * Tests that a request to an unreachable address fails within the connect
     * timeout window rather than the full request timeout — proving
     * CURLOPT_CONNECTTIMEOUT is actually being used.
     *
     * Uses 0.0.0.0:1 which is never routable. The connect should fail quickly
     * (within connect timeout) rather than waiting for the full timeout.
     */
    public function testConnectTimeoutIsEnforced(): void
    {
        $transport = new CurlArrTransport(30, 2); // 2s connect timeout

        $start = microtime(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cURL error');

        try {
            $transport->request('GET', 'http://0.0.0.0:1/', [], null);
        } finally {
            $elapsed = microtime(true) - $start;
            // Should fail within ~2 seconds (connect timeout), not 30 (full timeout)
            $this->assertLessThan(
                10, // Generous upper bound to avoid flakiness
                $elapsed,
                'Request should fail within connect timeout, not full request timeout'
            );
        }
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function everyMethodShape(): array
    {
        return [
            'GET' => ['GET', null],
            'POST' => ['POST', '{}'],
            'PUT' => ['PUT', '{}'],
            'DELETE' => ['DELETE', null],
            'custom verb' => ['PATCH', '{}'],
        ];
    }

    /**
     * TLS verification is stated explicitly on every transfer (the shared
     * handle is curl_reset() between calls, and libcurl/php.ini defaults are
     * not a contract): VERIFYPEER true and VERIFYHOST 2 (name must match),
     * alongside the protocol pin, for every method branch.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('everyMethodShape')]
    public function test_tls_verification_is_explicit_for_every_method(string $method, ?string $body): void
    {
        $build = new \ReflectionMethod(CurlArrTransport::class, 'buildOptions');
        /** @var array<int, mixed> $options */
        $options = $build->invoke(new CurlArrTransport(), $method, 'https://arr.example/api', [], $body);

        $this->assertArrayHasKey(CURLOPT_SSL_VERIFYPEER, $options);
        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertArrayHasKey(CURLOPT_SSL_VERIFYHOST, $options);
        $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
    }

    /**
     * The SHARED-HANDLE LAW (class docblock) is a declaration-level property:
     * the handle is `private static`, and NO per-instance handle exists. This
     * pins the shape — converting to a per-instance handle (dropping the
     * process-wide connection reuse) must be a deliberate, law-updating move,
     * not an accident this suite would silently tolerate.
     */
    public function test_the_shared_handle_is_process_wide_by_declaration(): void
    {
        $class = new \ReflectionClass(CurlArrTransport::class);
        $property = $class->getProperty('handle');

        $this->assertTrue($property->isStatic(), 'handle must be static (one per process).');
        $this->assertTrue($property->isPrivate(), 'handle must stay private.');
        $this->assertStringContainsString(
            'CurlHandle',
            (string) $property->getType(),
            'handle type must remain ?CurlHandle.'
        );

        $nonStatic = [];
        foreach ($class->getProperties() as $candidate) {
            if (!$candidate->isStatic()) {
                $nonStatic[] = $candidate->getName();
            }
        }
        $this->assertNotContains(
            'handle',
            $nonStatic,
            'No instance-level handle may coexist with the static sharing law.'
        );
    }

    /**
     * Functional proof of the same law: after a fresh (null) start, two
     * separate transport instances land their transfers on the SAME handle
     * object — instance B reuses (via curl_reset) what instance A created.
     * Uses a file:// URL, which the protocol pin rejects after the handle is
     * acquired, so no network or listening socket is involved.
     */
    public function test_two_instances_share_one_curl_handle(): void
    {
        $property = new \ReflectionProperty(CurlArrTransport::class, 'handle');
        $property->setValue(null, null); // deterministic start: force creation on call A

        $a = new CurlArrTransport(1, 1);
        $b = new CurlArrTransport(1, 1);

        try {
            try {
                $a->request('GET', 'file:///etc/shadow', [], null);
                $this->fail('file:// must be rejected by the protocol pin.');
            } catch (RuntimeException $e) {
                // The only legitimate outcome: transport-level cURL rejection.
                $this->assertStringStartsWith('cURL error', $e->getMessage());
            }
            /** @var mixed $handleA */
            $handleA = $property->getValue(null);
            $this->assertInstanceOf(\CurlHandle::class, $handleA);

            try {
                $b->request('GET', 'file:///etc/shadow', [], null);
                $this->fail('file:// must be rejected by the protocol pin.');
            } catch (RuntimeException $e) {
                $this->assertStringStartsWith('cURL error', $e->getMessage());
            }
            /** @var mixed $handleB */
            $handleB = $property->getValue(null);

            $this->assertInstanceOf(\CurlHandle::class, $handleB);
            $this->assertSame(
                spl_object_id($handleA),
                spl_object_id($handleB),
                'Both instances must run on the one shared static handle.'
            );
        } finally {
            // Leave no cross-test residue: the class keeps the handle for the
            // process by design, but this test created it deliberately early.
            $property->setValue(null, null);
        }
    }

    /**
     * In-flight guard (phlix-shared-001): with a transfer already owning the
     * shared handle, request() must refuse — BEFORE touching the handle. The
     * handle is forced null first, so a guard that failed to short-circuit
     * would curl_init() and the null-assertion below would convict it. The
     * refusal message must name the law ("in flight").
     */
    public function test_in_flight_guard_refuses_overlap_before_touching_the_handle(): void
    {
        $class = new \ReflectionClass(CurlArrTransport::class);
        $inFlight = $class->getProperty('inFlight');
        $handle = $class->getProperty('handle');

        $inFlight->setValue(null, true);
        $handle->setValue(null, null);

        try {
            $transport = new CurlArrTransport();
            try {
                $transport->request('GET', 'file:///etc/shadow', [], null);
                $this->fail('the in-flight guard must refuse an overlapping transfer.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('in flight', $e->getMessage());
            }
            $this->assertNull(
                $handle->getValue(null),
                'The guard must fire before curl_init(): a refused overlap leaves no handle behind.'
            );
        } finally {
            $inFlight->setValue(null, false);
            $handle->setValue(null, null);
        }
    }

    /**
     * In-flight guard release law (phlix-shared-001): the flag is held for the
     * duration of a transfer and freed in a `finally` — a thrown transport
     * error must never wedge the class into permanent refusal. file:// fails
     * after the guard engaged, so this exercises the failure-path release.
     */
    public function test_in_flight_guard_releases_after_a_failed_transfer(): void
    {
        $class = new \ReflectionClass(CurlArrTransport::class);
        $inFlight = $class->getProperty('inFlight');
        $handle = $class->getProperty('handle');

        $inFlight->setValue(null, false);

        try {
            $transport = new CurlArrTransport(1, 1);
            try {
                $transport->request('GET', 'file:///etc/shadow', [], null);
                $this->fail('file:// must be rejected by the protocol pin.');
            } catch (RuntimeException $e) {
                $this->assertStringStartsWith('cURL error', $e->getMessage());
            }
            $this->assertFalse(
                $inFlight->getValue(null),
                'A failed transfer must release the in-flight guard (finally law).'
            );
        } finally {
            $inFlight->setValue(null, false);
            $handle->setValue(null, null);
        }
    }

    /**
     * Structural pin of the sharing law's enforcement: the guard flag is
     * `private static` (process-wide like the handle itself) — an
     * instance-level flag would not serialize anything.
     */
    public function test_in_flight_guard_is_process_wide_by_declaration(): void
    {
        $property = (new \ReflectionClass(CurlArrTransport::class))->getProperty('inFlight');

        $this->assertTrue($property->isStatic(), 'inFlight must be static (one per process, like the handle).');
        $this->assertTrue($property->isPrivate(), 'inFlight must stay private.');
        $this->assertStringContainsString('bool', (string) $property->getType());
    }

    /**
     * Redirect ban + capped sink are restated explicitly on every option set
     * (phlix-shared-027 / phlix-shared-032): FOLLOWLOCATION is pinned false
     * (not merely absent, so a future default change or merged option set
     * cannot flip it silently), MAXREDIRS 0 is the second lock, and the
     * WRITEFUNCTION entry is a real closure.
     */
    public function test_redirect_ban_and_capped_sink_are_pinned_in_options(): void
    {
        $build = new \ReflectionMethod(CurlArrTransport::class, 'buildOptions');
        /** @var array<int, mixed> $options */
        $options = $build->invoke(new CurlArrTransport(), 'GET', 'https://arr.example/api', [], null);

        $this->assertArrayHasKey(CURLOPT_FOLLOWLOCATION, $options);
        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        $this->assertArrayHasKey(CURLOPT_MAXREDIRS, $options);
        $this->assertSame(0, $options[CURLOPT_MAXREDIRS]);
        $this->assertArrayHasKey(CURLOPT_WRITEFUNCTION, $options);
        $this->assertInstanceOf(\Closure::class, $options[CURLOPT_WRITEFUNCTION]);
    }

    /**
     * Cap arithmetic (phlix-shared-032): the sink accepts chunks while the
     * collected total stays within the cap and refuses, exactly, the first
     * byte beyond it — 5+5 on a 10-byte cap pass, the 11th byte returns 0
     * (CURLE_WRITE_ERROR abort) and flags the overflow; a refused chunk is
     * never partially kept.
     */
    public function test_write_function_caps_the_collected_response_at_the_limit(): void
    {
        $transport = new CurlArrTransport(1, 1, 10);
        $class = new \ReflectionClass($transport);
        $received = $class->getProperty('receivedBytes');
        $body = $class->getProperty('responseBody');
        $overflow = $class->getProperty('responseOverflow');
        $write = $class->getMethod('writeChunk');

        $this->assertSame(5, $write->invoke($transport, null, '12345'));
        $this->assertSame(5, $write->invoke($transport, null, '67890'));
        $this->assertSame(10, $received->getValue($transport));
        $this->assertSame('1234567890', $body->getValue($transport));
        $this->assertFalse($overflow->getValue($transport));

        $this->assertSame(0, $write->invoke($transport, null, 'x'));
        $this->assertTrue($overflow->getValue($transport));
        $this->assertSame(10, $received->getValue($transport), 'the refused chunk must not be partially kept');
        $this->assertSame('1234567890', $body->getValue($transport));
    }

    /**
     * The default cap is the documented house value: 32 MiB, the same number
     * TrashGuidesProvider holds (phlix-shared-032) — pinned literal so a
     * "tidy-up" change to the default trips a named law test.
     */
    public function test_default_response_cap_is_the_house_32_mib_value(): void
    {
        $this->assertSame(33_554_432, CurlArrTransport::DEFAULT_MAX_RESPONSE_BYTES);

        $prop = (new \ReflectionClass(new CurlArrTransport()))->getProperty('maxResponseBytes');
        $this->assertSame(
            CurlArrTransport::DEFAULT_MAX_RESPONSE_BYTES,
            $prop->getValue(new CurlArrTransport()),
            'A default-constructed transport must run with the house cap.'
        );
    }

    /**
     * End-to-end cap pin (phlix-shared-032, S-shared-01 r1 Minor cure):
     * request() against a REAL over-cap loopback response must throw the
     * dedicated, cap-naming error — the mapping that runs BEFORE the generic
     * 'cURL error' branch. The reflection pins (writeChunk arithmetic at the
     * sink, WRITEFUNCTION entry in the option set) never reach
     * responseOverflow === true inside request(), so the reviewer's
     * overflow-map-off mutant (`if ($this->responseOverflowed())` → `if
     * (false)`) survived the entire committed suite: the error degraded to a
     * generic cURL failure and nothing went red. This pin convicts it — the
     * dedicated message is asserted literally (cap value included), and a
     * degraded generic 'cURL error: ...' fails both assertions.
     *
     * Hermetic: php child (proc_open, killed in finally) on a reserved
     * ephemeral 127.0.0.1 port answers one 2048-byte body; the transport runs
     * (timeout 2 s, connect 1 s, cap 64 bytes), so the first body chunk
     * exceeds the cap and the sink aborts mid-transfer. No DNS, no fixed
     * port, no external network is ever touched.
     */
    public function test_response_over_the_cap_maps_to_the_dedicated_cap_error_end_to_end(): void
    {
        $dir = sys_get_temp_dir() . '/curl-cap-e2e-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700) && !is_dir($dir)) {
            $this->fail("could not create the scratch dir $dir");
        }

        $scriptPath = $dir . '/over_cap_server.php';
        $witnessPath = $dir . '/served.log';

        /** @var resource|false $process */
        $process = false;
        /** @var array<int, mixed> $pipes */
        $pipes = [];

        try {
            $port = $this->reserveLoopbackPort();

            if (
                file_put_contents($scriptPath, self::OVER_CAP_SERVER_SCRIPT) === false
                || file_put_contents($witnessPath, '') === false
            ) {
                $this->fail("could not seed the scratch dir $dir");
            }

            $process = proc_open(
                [PHP_BINARY, $scriptPath, (string) $port, $witnessPath],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes
            );
            if (!is_resource($process)) {
                $this->fail('could not start the loopback over-cap server child');
            }

            $ready = is_resource($pipes[1] ?? null) ? fgets($pipes[1]) : false;
            if ($ready !== "READY\n") {
                $stderr = is_resource($pipes[2] ?? null) ? (string) stream_get_contents($pipes[2]) : '';
                $this->fail('over-cap server child never reported READY; stderr: ' . $stderr);
            }

            $transport = new CurlArrTransport(2, 1, 64);

            try {
                $outcome = $transport->request('GET', 'http://127.0.0.1:' . $port . '/over-cap.json', [], null);
                $this->fail(
                    'an over-cap response must abort the transfer; returned status=' . $outcome['status']
                );
            } catch (RuntimeException $exception) {
                $this->assertMatchesRegularExpression(
                    '/exceeded the \d+-byte cap/',
                    $exception->getMessage(),
                    'the dedicated overflow mapping must fire before the generic cURL-error branch'
                );
                $this->assertStringStartsWith(
                    'cURL response exceeded the 64-byte cap',
                    $exception->getMessage(),
                    'the cap in force must be named in the error'
                );
            }

            $this->assertStringContainsString(
                "SERVED\n",
                (string) file_get_contents($witnessPath),
                'the loopback server must have answered before the capped sink aborted the transfer'
            );
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach (['over_cap_server.php', 'served.log'] as $artifact) {
                if (is_file($dir . '/' . $artifact)) {
                    unlink($dir . '/' . $artifact);
                }
            }
            @rmdir($dir);
        }
    }

    /**
     * Reserve a free ephemeral 127.0.0.1 port by binding port 0 and reading
     * the kernel-assigned number back — the exact discipline of
     * TrashGuidesTlsMaterial::reservePort(), inlined so this pin stays inside
     * the step's locked test file.
     */
    private function reserveLoopbackPort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            $this->fail("cannot reserve a loopback port: $errstr");
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        if ($port <= 0) {
            $this->fail("could not read back a port from '$name'");
        }

        return $port;
    }

    /**
     * Anti-rot (phlix-shared-001/027): the disproven docblock premises —
     * hub hooking curl, the blocking call yielding a coroutine, the shared
     * handle interleaving as a live concern — must not re-enter the three
     * Arr classes, and the corrected runtime-truth notes (finding markers +
     * the honest hazard/law words) must stay present.
     */
    public function test_runtime_truth_docblocks_never_resurrect_the_hooked_curl_premise(): void
    {
        $stale = [
            'the blocking call yields its coroutine',
            "as in phlix-hub's Swoole worker start",
            'the default transport is kept',
            'interleave on the single handle',
        ];

        $docs = [];
        foreach ([AbstractArrClient::class, CurlArrTransport::class, ArrTransportInterface::class] as $class) {
            $docs[$class] = (string) (new \ReflectionClass($class))->getDocComment();
        }

        foreach ($docs as $class => $doc) {
            foreach ($stale as $phrase) {
                $this->assertStringNotContainsString(
                    $phrase,
                    $doc,
                    $class . ' must not re-assert the disproven premise: ' . $phrase
                );
            }
        }

        $this->assertStringContainsString('phlix-shared-001', $docs[AbstractArrClient::class]);
        $this->assertStringContainsString('STALL', $docs[AbstractArrClient::class]);
        $this->assertStringContainsString('phlix-shared-001', $docs[CurlArrTransport::class]);
        $this->assertStringContainsString('IN-FLIGHT GUARD', $docs[CurlArrTransport::class]);
        $this->assertStringContainsString('phlix-shared-001', $docs[ArrTransportInterface::class]);
        $this->assertStringContainsString('phlix-shared-027', $docs[ArrTransportInterface::class]);
        $this->assertStringContainsString('MUST NOT follow', $docs[ArrTransportInterface::class]);
    }
}
