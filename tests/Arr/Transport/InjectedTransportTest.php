<?php

/**
 * Injected Transport Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Arr\Transport;

use PHPUnit\Framework\TestCase;
use Phlix\Shared\Arr\RadarrClient;
use Phlix\Shared\Arr\Transport\CurlArrTransport;
use RuntimeException;

/**
 * Verifies the F2b transport seam: an injected {@see \Phlix\Shared\Arr\Transport\ArrTransportInterface}
 * short-circuits cURL entirely, while the default {@see CurlArrTransport} preserves
 * the original blocking behaviour.
 *
 * @package Phlix\Shared\Tests\Arr\Transport
 */
class InjectedTransportTest extends TestCase
{
    /**
     * A deliberately unroutable base URL. If any real cURL call were made, the
     * default {@see CurlArrTransport} would raise a "cURL error" RuntimeException.
     * The injected fake must short-circuit before any network I/O, so the call
     * succeeds — proving no `curl_exec` is reached when a transport is injected.
     */
    private const UNROUTABLE_BASE_URL = 'http://0.0.0.0:1';

    public function testInjectedTransportShortCircuitsCurl(): void
    {
        $fake = new FakeArrTransport(200, '[{"id":1,"title":"Test Movie"}]');

        $client = new RadarrClient(
            self::UNROUTABLE_BASE_URL,
            'test-api-key',
            null,
            30,
            $fake
        );

        // If cURL were invoked against the unroutable URL this would throw; the fake
        // returns the canned body instead, proving the seam fully replaces cURL.
        $result = $client->getMovies();

        $this->assertSame([['id' => 1, 'title' => 'Test Movie']], $result);
        $this->assertCount(1, $fake->calls, 'Exactly one request must flow through the injected transport.');
        $this->assertSame('GET', $fake->calls[0]['method']);
        $this->assertSame(self::UNROUTABLE_BASE_URL . '/api/v3/movie', $fake->calls[0]['url']);
        $this->assertNull($fake->calls[0]['body']);
        $this->assertContains('X-Api-Key: test-api-key', $fake->calls[0]['headers']);
    }

    public function testInjectedTransportReceivesEncodedPostBody(): void
    {
        $fake = new FakeArrTransport(201, '{"id":10,"tmdbId":123456}');

        $client = new RadarrClient(
            self::UNROUTABLE_BASE_URL,
            'test-api-key',
            null,
            30,
            $fake
        );

        $result = $client->addMovie(123456, 2, '/movies', true);

        $this->assertSame(10, $result['id']);
        $this->assertSame('POST', $fake->calls[0]['method']);
        $this->assertNotNull($fake->calls[0]['body']);
        /** @var string $sentBody */
        $sentBody = $fake->calls[0]['body'];
        $decoded = json_decode($sentBody, true);
        $this->assertIsArray($decoded);
        $this->assertSame(123456, $decoded['tmdbId']);
    }

    public function testInjectedTransportStatusErrorsStillMap(): void
    {
        $fake = new FakeArrTransport(401, '');

        $client = new RadarrClient(
            self::UNROUTABLE_BASE_URL,
            'test-api-key',
            null,
            30,
            $fake
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Radarr API authentication failed (401)');
        $client->getMovies();
    }

    /**
     * Control: with NO injected transport the client falls back to the blocking
     * {@see CurlArrTransport}, which DOES perform real cURL — so an unroutable URL
     * raises a transport-level cURL error. This proves the fake genuinely replaces
     * cURL rather than the call being a no-op either way.
     */
    public function testDefaultTransportPerformsRealCurl(): void
    {
        $client = new RadarrClient(self::UNROUTABLE_BASE_URL, 'test-api-key', null, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cURL error');
        $client->getMovies();
    }

    public function testDefaultTransportIsCurlArrTransport(): void
    {
        $client = new RadarrClient('http://localhost:7878', 'k');

        $reflection = new \ReflectionClass($client);
        $transportProp = $reflection->getProperty('transport');
        $transportProp->setAccessible(true);

        $this->assertInstanceOf(CurlArrTransport::class, $transportProp->getValue($client));
    }
}
