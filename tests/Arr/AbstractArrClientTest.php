<?php

/**
 * Abstract Arr Client Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Arr;

use PHPUnit\Framework\TestCase;
use Phlix\Shared\Arr\RadarrClient;
use Psr\Log\LoggerInterface;
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

    /**
     * @dataProvider apiKeyLineBreakProvider
     */
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
}
