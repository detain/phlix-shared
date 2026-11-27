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
 * @package Phlix\Shared\Tests\Arr\Transport
 * @since 0.11.0
 */
class CurlArrTransportTest extends TestCase
{
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
