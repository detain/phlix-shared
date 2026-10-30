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
use Phlix\Shared\Arr\Transport\CurlArrTransport;
use RuntimeException;

/**
 * Unit tests for CurlArrTransport.
 *
 * Tests protocol pinning (CURLOPT_PROTOCOLS, CURLOPT_REDIR_PROTOCOLS) and
 * connect timeout (CURLOPT_CONNECTTIMEOUT) are properly configured.
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
}
