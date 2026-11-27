<?php

/**
 * Abstract Arr Client Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Arr;

use Phlix\Shared\Arr\AbstractArrClient;
use Phlix\Shared\Arr\RadarrClient;
use Phlix\Shared\Arr\Transport\ArrTransportInterface;
use Phlix\Shared\Arr\Transport\CurlArrTransport;
use Phlix\Shared\Tests\Arr\Transport\FakeArrTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Test-local exposure seam ([S-shared-27 fix1 (a)]): respondingClient() must
 * carry an explicit return type, and the anon client it builds is unnameable,
 * so the one public method callers invoke on it (fetch, the protected GET
 * widened for path-driven redaction pins) is typed through this interface.
 */
interface ExposedArrFetchClient
{
    /**
     * @param string $path Arbitrary API path the test wants request() to see.
     *
     * @return array<array-key, mixed>
     */
    public function fetch(string $path): array;
}

/**
 * Unit tests for AbstractArrClient scheme validation and HTTP warning.
 *
 * @package Phlix\Shared\Tests\Arr
 * @since 0.11.0
 */
class AbstractArrClientTest extends TestCase
{
    /**
     * Tests that an invalid scheme (ftp) throws RuntimeException.
     */
    public function testInvalidSchemeThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid baseUrl scheme/i');

        new class ('ftp://localhost:7878', 'test-key') extends RadarrClient {
            // Anonymous class to bypass MockableRadarrClient pattern
            protected function vendorName(): string
            {
                return 'Test';
            }
        };
    }

    /**
     * Tests that a file:// scheme throws RuntimeException.
     */
    public function testFileSchemeThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid baseUrl scheme/i');

        new class ('file:///tmp/test', 'test-key') extends RadarrClient {
            protected function vendorName(): string
            {
                return 'Test';
            }
        };
    }

    /**
     * Tests that https:// scheme does NOT log a warning.
     */
    public function testHttpsSchemeDoesNotLogWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        new class ('https://localhost:7878', 'test-key', $logger) extends RadarrClient {
            protected function vendorName(): string
            {
                return 'Test';
            }
        };
    }

    /**
     * Tests that http:// scheme logs exactly one warning about clear-text API key.
     */
    public function testHttpSchemeLogsWarningOnce(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('X-Api-Key is sent in clear text'));

        new class ('http://localhost:7878', 'test-key', $logger) extends RadarrClient {
            protected function vendorName(): string
            {
                return 'Test';
            }
        };
    }

    /**
     * Tests that each http:// client instance logs its own warning (not shared).
     */
    public function testEachHttpInstanceLogsItsOwnWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        // Two separate instances should each log their own warning
        $logger->expects($this->exactly(2))
            ->method('warning')
            ->with($this->stringContains('X-Api-Key is sent in clear text'));

        new class ('http://localhost:7878', 'key1', $logger) extends RadarrClient {
            protected function vendorName(): string
            {
                return 'Test';
            }
        };
        new class ('http://localhost:7878', 'key2', $logger) extends RadarrClient {
            protected function vendorName(): string
            {
                return 'Test';
            }
        };
    }

    /**
     * Tests that the warning message includes the vendor name.
     */
    public function testWarningMessageIncludesVendorName(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Radarr'));

        new class ('http://localhost:7878', 'test-key', $logger) extends RadarrClient {
            protected function vendorName(): string
            {
                return 'Radarr';
            }
        };
    }

    /**
     * LW-055 [review shared__srphp Info #16]: the X-Api-Key header is built
     * by raw concatenation, so a key carrying CR or LF would reach the
     * transport as a malformed (or injecting) header. The failure must be
     * credited to this seam — rejected at construction, not downstream in
     * curl. Red-first at the parent commit: these keys constructed fine and
     * only the transport noticed.
     *
     * @return array<string, array{string}>
     */
    public static function apiKeyLineBreakProvider(): array
    {
        return [
            'bare CR'   => ["key\rinjected"],
            'bare LF'   => ["key\ninjected"],
            'CRLF'      => ["key\r\nX-Injected: 1"],
            'trailing CR' => ["key\r"],
            'trailing LF' => ["key\n"],
        ];
    }

    #[DataProvider('apiKeyLineBreakProvider')]
    public function testApiKeyWithLineBreakIsRejectedAtConstruction(string $apiKey): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/API key must not contain CR or LF/');

        new class ('https://localhost:7878', $apiKey) extends RadarrClient {
            protected function vendorName(): string
            {
                return 'Test';
            }
        };
    }

    /**
     * LW-055: real *arr keys are base64-ish and carry +, / and = — the
     * screen rejects line breaks only, never legitimate key punctuation.
     */
    public function testApiKeyWithBase64PunctuationIsAccepted(): void
    {
        $client = new class ('https://localhost:7878', 'aB+c/d=e==1234567890') extends RadarrClient {
            protected function vendorName(): string
            {
                return 'Test';
            }
        };

        $this->assertInstanceOf(RadarrClient::class, $client);
    }

    /**
     * LW-056 [review shared__srphp #6]: ext-curl is only require-dev/suggest,
     * yet the constructor built a CurlArrTransport unconditionally — on a curl-
     * less runtime the first default-transport client died with a fatal
     * undefined-function error from inside the transport. The named
     * RuntimeException must fire at the seam that makes the choice. Red-first
     * at the parent commit: the probe method does not exist, so this
     * construction must have succeeded and the expectation below is red.
     */
    public function testMissingCurlSupportFailsLoudAtConstruction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires the PHP curl extension \(ext-curl\)/');

        new class ('http://localhost:7878', 'test-key') extends RadarrClient {
            protected static function hasCurlSupport(): bool
            {
                return false;
            }
        };
    }

    /**
     * LW-056: the fail-loud is about the BUNDLED default only — a consumer
     * that injects its own transport must construct fine even where curl is
     * missing (the documented escape hatch).
     */
    public function testInjectedTransportNeedsNoCurlSupport(): void
    {
        $client = new class (
            'http://localhost:7878',
            'test-key',
            null,
            30,
            new FakeArrTransport()
        ) extends RadarrClient {
            protected static function hasCurlSupport(): bool
            {
                return false;
            }
        };

        $this->assertInstanceOf(RadarrClient::class, $client);
    }

    /**
     * LW-056: the capability probe is a real structural seam on the base
     * class, and on this host (ext-curl loaded — the whole test suite relies
     * on it via InjectedTransportTest::testDefaultTransportPerformsRealCurl)
     * it answers true. Red-first at the parent commit: the method does not
     * exist on AbstractArrClient.
     */
    public function testCurlSupportProbeExistsAndMatchesHostRuntime(): void
    {
        $this->assertTrue(
            (new ReflectionMethod(AbstractArrClient::class, 'hasCurlSupport'))->isStatic(),
            'hasCurlSupport() must be a static probe on the base class.'
        );
        $this->assertTrue(
            (new ReflectionMethod(RadarrClient::class, 'hasCurlSupport'))->invoke(null),
            'The host runs the suite with ext-curl loaded; the probe must say so.'
        );
    }

    /**
     * LW-056 [review shared__srphp #7]: the base class stored the timeout in
     * a property no code ever read — the value reaches the transport via the
     * constructor argument only. The inert copy is dropped; this pin reddens
     * at the parent commit, where the property exists.
     */
    public function testBaseClassCarriesNoInertTimeoutProperty(): void
    {
        $this->assertFalse(
            (new ReflectionClass(AbstractArrClient::class))->hasProperty('timeout'),
            'AbstractArrClient::$timeout was write-once and never read — dropped.'
        );
    }

    /**
     * Reaffirmation of the LW-056 law after phlix-shared-032 added a second
     * timing knob: the connect timeout must reach the transport as a
     * constructor argument only — no new inert property on the base class.
     */
    public function testBaseClassCarriesNoInertConnectTimeoutProperty(): void
    {
        $this->assertFalse(
            (new ReflectionClass(AbstractArrClient::class))->hasProperty('connectTimeout'),
            'connectTimeout is a forwarded-only value; storing it here would recreate LW-056.'
        );
    }

    /**
     * phlix-shared-032: the client used to forward only the overall timeout to
     * the default transport, leaving the connect phase pinned at the
     * transport's own default regardless of the consumer's request. Both
     * timing knobs must now arrive on the bundled default: an untouched
     * client keeps 30/5, and a client configured (7, null, 2) forwards
     * exactly those. Red-first at the parent commit: defaultTransport() had a
     * single parameter.
     */
    public function testDefaultTransportReceivesBothTimeouts(): void
    {
        $readTransport = function (AbstractArrClient $client): CurlArrTransport {
            $transport = (new ReflectionClass(AbstractArrClient::class))->getProperty('transport')->getValue($client);
            $this->assertInstanceOf(CurlArrTransport::class, $transport);

            return $transport;
        };
        $readInt = function (object $transport, string $property): int {
            $value = (new ReflectionClass($transport))->getProperty($property)->getValue($transport);
            $this->assertIsInt($value);

            return $value;
        };

        $default = new class ('https://localhost:7878', 'test-key') extends RadarrClient {
            protected function vendorName(): string
            {
                return 'Test';
            }
        };
        $defaultTransport = $readTransport($default);
        $this->assertSame(30, $readInt($defaultTransport, 'timeout'));
        $this->assertSame(5, $readInt($defaultTransport, 'connectTimeout'));

        $tuned = new class ('https://localhost:7878', 'test-key', null, 7, null, 2) extends RadarrClient {
            protected function vendorName(): string
            {
                return 'Test';
            }
        };
        $tunedTransport = $readTransport($tuned);
        $this->assertSame(7, $readInt($tunedTransport, 'timeout'));
        $this->assertSame(2, $readInt($tunedTransport, 'connectTimeout'));
    }

    /**
     * S-shared-10 r1 FINDING-1 (M5 arm-1 witness): the 404 echo must route the
     * caller-supplied $path through SecretRedactor::redactUri(). The path here
     * carries only an `?apikey=`-shaped value that is NOT the configured key,
     * so the `***` below can come from no other leg — a revert to raw $path
     * (the review's M5) leaks 'SECRET123', and even a redact()-only swap
     * (exact-value kernel, pattern-blind) leaks it. Both convictions land on
     * THIS arm alone; the 3xx pin stays green.
     */
    public function testNotFoundEchoRedactsSecretShapedPath(): void
    {
        $client = $this->respondingClient(404, '');

        try {
            $client->fetch('/api/v3/movie?apikey=SECRET123');
            $this->fail('Expected RuntimeException for HTTP 404.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Test API resource not found (404)', $e->getMessage());
            $this->assertStringContainsString('***', $e->getMessage());
            $this->assertStringNotContainsString('SECRET123', $e->getMessage());
        }
    }

    /**
     * S-shared-10 r1 FINDING-1 (M5 arm-2 witness): same wiring at the 3xx
     * echo. This path carries ONLY userinfo-shaped material — no credential-
     * named query value and nothing the configured key covers — so the `***`
     * is proof this call site still runs the redactUri() URI arm; reverting it
     * to raw $path leaks 'hunter2-prod' and reddens exactly THIS arm.
     */
    public function testRedirectEchoRedactsUserinfoBearingPath(): void
    {
        $client = $this->respondingClient(302, '');

        try {
            $client->fetch('/moved?to=http://sonarr:hunter2-prod@metrics.internal:9090/ping');
            $this->fail('Expected RuntimeException for HTTP 302.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Test API redirect not followed (HTTP 302)', $e->getMessage());
            $this->assertStringContainsString('***', $e->getMessage());
            $this->assertStringNotContainsString('hunter2-prod', $e->getMessage());
        }
    }

    /**
     * phlix-shared-27 (finding phlix-shared-033): ArrTransportInterface's
     * {status:int, body:string} return shape is a phpdoc promise the
     * interface cannot enforce at runtime. Each canned response below
     * breaks that contract in exactly one way; before the seam guard every
     * one of them degraded into silent success, an undefined-key warning
     * storm, or a raw TypeError from json_decode — never a domain error
     * naming the broken shape. At the parent these arms are red; with the
     * guard shipped each mutation-reverts to its pre-fix behavior.
     *
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function malformedTransportResponseProvider(): array
    {
        return [
            'empty array' => [[]],
            'missing status' => [['body' => '{}']],
            'missing body' => [['status' => 200]],
            'unrelated keys' => [['code' => 200, 'payload' => '{}']],
            'string status' => [['status' => '200', 'body' => '{}']],
            'float status' => [['status' => 200.0, 'body' => '{}']],
            'null status' => [['status' => null, 'body' => '{}']],
            'null body' => [['status' => 200, 'body' => null]],
            'array body' => [['status' => 200, 'body' => []]],
            'int body' => [['status' => 200, 'body' => 5]],
        ];
    }

    /**
     * phlix-shared-033, part 1: a transport response that violates the
     * {status:int, body:string} shape must fail loud at the request seam
     * with a domain RuntimeException naming the contract — for every
     * violation leg in the provider.
     *
     * @param array<array-key, mixed> $response The canned violation.
     */
    #[DataProvider('malformedTransportResponseProvider')]
    public function testMalformedTransportResponseFailsLoudAtTheSeam(array $response): void
    {
        $transport = new class ($response) implements ArrTransportInterface {
            /**
             * @param array<array-key, mixed> $response The canned violation.
             */
            public function __construct(private array $response)
            {
            }

            /**
             * @param array<string, string> $headers
             *
             * @return array<array-key, mixed>
             */
            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                return $this->response;
            }

            public function close(): void
            {
            }
        };
        $client = new class (
            'http://0.0.0.0:1',
            'configured-key-9e1c',
            null,
            30,
            $transport
        ) extends RadarrClient {
            protected function vendorName(): string
            {
                return 'Test';
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test transport returned an invalid response shape');

        $client->getMovies();
    }

    /**
     * The seam guard parses the two contract keys; it must not reject a
     * compliant response for carrying extras. This leg is green at the
     * parent and stays green with the guard shipped — the anti-over-
     * tightening pin proving the fix validates, not narrows, the shape.
     */
    public function testTransportResponseWithExtraKeysIsStillValid(): void
    {
        $transport = new class implements ArrTransportInterface {
            /**
             * @param array<string, string> $headers
             *
             * @return array<array-key, mixed>
             */
            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                return ['status' => 200, 'body' => '[]', 'headers' => ['X-Extra' => '1']];
            }

            public function close(): void
            {
            }
        };
        $client = new class (
            'http://0.0.0.0:1',
            'configured-key-9e1c',
            null,
            30,
            $transport
        ) extends RadarrClient {
            protected function vendorName(): string
            {
                return 'Test';
            }
        };

        $this->assertSame([], $client->getMovies());
    }

    /**
     * Builds a 'Test' client on the unroutable base whose injected fake answers
     * with the given status, exposing the protected GET so a test can drive
     * request() with an arbitrary caller-supplied $path (the redaction pins
     * above need paths no public endpoint method ever sends).
     *
     * @param int    $status Canned HTTP status the fake transport returns.
     * @param string $body   Canned response body ('' — the error arms throw first).
     *
     * @return AbstractArrClient&ExposedArrFetchClient
     */
    private function respondingClient(int $status, string $body): AbstractArrClient&ExposedArrFetchClient
    {
        return new class (
            'http://0.0.0.0:1',
            'configured-key-9e1c',
            null,
            30,
            new FakeArrTransport($status, $body)
        ) extends RadarrClient implements ExposedArrFetchClient {
            protected function vendorName(): string
            {
                return 'Test';
            }

            /**
             * @return array<array-key, mixed>
             */
            public function fetch(string $path): array
            {
                return $this->get($path);
            }
        };
    }
}
