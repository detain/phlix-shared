<?php

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
}
