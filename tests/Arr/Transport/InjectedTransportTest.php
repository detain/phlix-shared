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
        $this->assertSame('/movies', $decoded['rootFolderPath']);
        $this->assertArrayNotHasKey('rootFolder', $decoded);
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
     * M-SHARED-33: a 4xx/5xx body must reach the message — the *arr APIs put
     * the actionable text there, and the old message stopped at the status.
     */
    public function testHttpErrorMessageCarriesResponseBodyExcerpt(): void
    {
        $fake = new FakeArrTransport(400, '{"message":"Root folder not found"}');

        $client = new RadarrClient(self::UNROUTABLE_BASE_URL, 'k', null, 30, $fake);

        try {
            $client->getMovies();
            $this->fail('Expected RuntimeException for HTTP 400.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Radarr API error: HTTP 400', $e->getMessage());
            $this->assertStringContainsString('Root folder not found', $e->getMessage());
        }
    }

    /**
     * M-SHARED-33: whitespace inside the body collapses to one line, and a
     * body over the 300-byte cap is truncated — a full HTML error page must
     * never bloat the log line.
     */
    public function testHttpErrorBodyExcerptIsCollapsedAndCapped(): void
    {
        $noisy  = "line one\n\t  line two   " . str_repeat('x', 400);
        $fake   = new FakeArrTransport(500, $noisy);
        $client = new RadarrClient(self::UNROUTABLE_BASE_URL, 'k', null, 30, $fake);

        try {
            $client->getMovies();
            $this->fail('Expected RuntimeException for HTTP 500.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString("\n", $e->getMessage());
            $this->assertStringContainsString('line one line two', $e->getMessage());
            $this->assertLessThanOrEqual(
                strlen('Radarr API error: HTTP 500 — ') + 303 + 100,
                strlen($e->getMessage()),
                'Excerpt must be capped (300 bytes) with the literal ellipsis.'
            );
            $this->assertStringEndsWith('...', $e->getMessage());
        }
    }

    /**
     * M-SHARED-33: an empty error body contributes no excerpt separator.
     */
    public function testHttpErrorWithEmptyBodyKeepsStatusOnlyMessage(): void
    {
        $fake   = new FakeArrTransport(503, '');
        $client = new RadarrClient(self::UNROUTABLE_BASE_URL, 'k', null, 30, $fake);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Radarr API error: HTTP 503');

        $client->getMovies();
    }

    /**
     * LW-054 [review shared__secaudit #2]: the >=400 message must never carry
     * the caller's API key, even when the vendor error body echoes it back.
     * Redaction belongs at the creation site in request() — only
     * triggerDownload/testConnection re-wrapped with SecretRedactor, so every
     * other client method raised the raw (leaking) message. Red-first at the
     * parent commit: the key rode out verbatim here.
     */
    public function testHttpErrorMessageRedactsApiKeyWithoutCatchSite(): void
    {
        $key  = 'leak-check-key-9f3a';
        $fake = new FakeArrTransport(500, '{"detail":"no access with key ' . $key . ' granted"}');

        $client = new RadarrClient(self::UNROUTABLE_BASE_URL, $key, null, 30, $fake);

        try {
            $client->getMovies();
            $this->fail('Expected RuntimeException for HTTP 500.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString($key, $e->getMessage());
            $this->assertStringContainsString('Radarr API error: HTTP 500', $e->getMessage());
            $this->assertStringContainsString('no access with key *** granted', $e->getMessage());
        }
    }

    /**
     * LW-054: redaction runs BEFORE the 300-byte cap, so a key that would be
     * cut mid-value by truncation cannot survive as a partial leak. Red-first
     * at the parent: the raw body reached the cap unchanged and the visible
     * head of the key shipped in the message.
     */
    public function testHttpErrorRedactionPrecedesExcerptCap(): void
    {
        $key  = 'headsecret-7b2c-deadbeef';
        $fake = new FakeArrTransport(500, str_repeat('x', 280) . ' ' . $key . ' tail');

        $client = new RadarrClient(self::UNROUTABLE_BASE_URL, $key, null, 30, $fake);

        try {
            $client->getMovies();
            $this->fail('Expected RuntimeException for HTTP 500.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString($key, $e->getMessage());
            $this->assertStringNotContainsString('headsecret', $e->getMessage());
            $this->assertStringContainsString('***', $e->getMessage());
        }
    }

    /**
     * phlix-shared-033, part 2: for a 200/2xx whose body is not JSON, the
     * body IS the diagnostic (a vendor HTML error page behind a success
     * status). The old message dropped it entirely — the throw now carries
     * the same capped single-line excerpt as the >=400 arm. Red-first at
     * the parent: the bare message contained none of the page.
     */
    public function testInvalidJsonMessageCarriesBodyExcerpt(): void
    {
        $fake = new FakeArrTransport(200, "<html>\n  <body>nginx is down (radarr-proxy)</body>\n</html>");

        $client = new RadarrClient(self::UNROUTABLE_BASE_URL, 'excerpt-check-key', null, 30, $fake);

        try {
            $client->getMovies();
            $this->fail('Expected RuntimeException for an undecodable body.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Invalid JSON response from Radarr', $e->getMessage());
            $this->assertStringContainsString('nginx is down (radarr-proxy)', $e->getMessage());
            $this->assertStringNotContainsString("\n", $e->getMessage());
        }
    }

    /**
     * phlix-shared-033: the excerpt on the invalid-JSON arm runs the same
     * redact-before-cap law as the >=400 arm (LW-054). The key starts at
     * byte 292 of the body, so capping first would ship its head; only the
     * correct order collapses it to '***' inside the visible window.
     * Red-first at the parent, where no excerpt existed at all.
     */
    public function testInvalidJsonExcerptRedactsApiKeyAndPrecedesCap(): void
    {
        $key  = 'leakhead-4d8b-cafe1234';
        $fake = new FakeArrTransport(200, str_repeat('x', 290) . ' ' . $key);

        $client = new RadarrClient(self::UNROUTABLE_BASE_URL, $key, null, 30, $fake);

        try {
            $client->getMovies();
            $this->fail('Expected RuntimeException for an undecodable body.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString($key, $e->getMessage());
            $this->assertStringNotContainsString('leakhead-4d8b', $e->getMessage());
            $this->assertStringContainsString('***', $e->getMessage());
        }
    }

    /**
     * phlix-shared-033: the appended excerpt keeps the 300-byte cap and the
     * ellipsis, so an oversized body can never bloat the log line — the
     * message is prefix + at most ' — ' + 300 bytes + '...'.
     */
    public function testInvalidJsonExcerptIsCapped(): void
    {
        $fake = new FakeArrTransport(200, str_repeat('z', 1000));

        $client = new RadarrClient(self::UNROUTABLE_BASE_URL, 'cap-check-key', null, 30, $fake);

        $prefix = 'Invalid JSON response from Radarr';
        try {
            $client->getMovies();
            $this->fail('Expected RuntimeException for an undecodable body.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith($prefix, $e->getMessage());
            $this->assertStringEndsWith('...', $e->getMessage());
            $this->assertLessThanOrEqual(strlen($prefix) + strlen(' — ') + 300 + 3, strlen($e->getMessage()));
        }
    }

    /**
     * phlix-shared-033 control: a whitespace-only undecodable body
     * contributes nothing (detailFromBody trims to empty), so the message
     * stays exactly the bare sentence — no dangling ' — ' separator. Green
     * at the parent (the bare message WAS the whole story) and green after
     * shipping: the pin is the no-separator arm, and it reddens if the
     * excerpt is ever appended unconditionally.
     */
    public function testInvalidJsonWhitespaceBodyKeepsBareMessage(): void
    {
        $fake = new FakeArrTransport(200, "   \n\t  ");

        $client = new RadarrClient(self::UNROUTABLE_BASE_URL, 'bare-check-key', null, 30, $fake);

        try {
            $client->getMovies();
            $this->fail('Expected RuntimeException for an undecodable body.');
        } catch (RuntimeException $e) {
            $this->assertSame('Invalid JSON response from Radarr', $e->getMessage());
        }
    }

    /**
     * M-SHARED-33: a 3xx is never success. A 301 with an empty body used to
     * fall through the status mapping and decode as "no data" ([]).
     */
    public function testRedirectThrowsInsteadOfSilentSuccess(): void
    {
        $fake   = new FakeArrTransport(301, '');
        $client = new RadarrClient(self::UNROUTABLE_BASE_URL, 'k', null, 30, $fake);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/redirect not followed \(HTTP 301\)/');

        // On the old code this call returned [] (silent success) and the
        // expected exception never fired — that is the red this pin forces.
        $client->getMovies();
    }

    /**
     * M-SHARED-33: a redirect WITH a body (e.g. an HTML "Moved" page) must
     * also throw, not reach the JSON decoder.
     */
    public function testRedirectWithBodyThrowsNotJsonError(): void
    {
        $fake   = new FakeArrTransport(302, '<html>Moved</html>');
        $client = new RadarrClient(self::UNROUTABLE_BASE_URL, 'k', null, 30, $fake);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/redirect not followed \(HTTP 302\)/');
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
