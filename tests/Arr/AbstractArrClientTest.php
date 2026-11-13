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
use Phlix\Shared\Tests\Arr\Transport\FakeArrTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

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
}
