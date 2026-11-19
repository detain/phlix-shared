<?php

/**
 * VersionMarkerFetcherInterface Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Updates;

use Phlix\Shared\Updates\VersionMarkerFetcherInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Characterization of the S74/S75 exact twin (v0.57 leg 0.12, M-23).
 * Twins: phlix-server src/Server/Updates/VersionMarkerFetcherInterface.php
 * and phlix-hub src/Hub/Updates/VersionMarkerFetcherInterface.php — diff
 * proven: namespace/prose only (the server copy carries the longer
 * "why not PluginCatalogService::defaultFetcher()" rationale, which the hub
 * copy kept in shortened form; the law is identical).
 */
#[CoversNothing]
final class VersionMarkerFetcherInterfaceTest extends TestCase
{
    public function testInterfaceDeclaresExactlyTheFetchContract(): void
    {
        $reflection = new ReflectionClass(VersionMarkerFetcherInterface::class);

        self::assertTrue($reflection->isInterface());

        $methods = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods()
        );

        self::assertSame(['fetch'], $methods);
    }

    public function testFetchSignatureIsUrlPlusOnceCallback(): void
    {
        $method = new ReflectionMethod(VersionMarkerFetcherInterface::class, 'fetch');

        self::assertSame(['url', 'onDone'], array_map(
            static fn($parameter): string => $parameter->getName(),
            $method->getParameters()
        ));
        self::assertSame('void', (string) $method->getReturnType());
    }

    public function testASingleCodePathFakeDrivesTheContract(): void
    {
        // The fork-free law: implementations deliver EXACTLY ONE callback arm
        // (body xor error). Driven here by a one-path fake — under PHPUnit the
        // coroutine arm of the rejected channel-based shapes was structurally
        // unreachable (the S170 defect class the server docblock names).
        $fetcher = new class implements VersionMarkerFetcherInterface {
            public function fetch(string $url, callable $onDone): void
            {
                $onDone(str_contains($url, 'localhost') ? null : 'v9.9.9', str_contains($url, 'localhost') ? 'refused' : null);
            }
        };

        $body = null;
        $error = null;
        $fetcher->fetch('https://example.invalid/marker.txt', static function (?string $b, ?string $e) use (&$body, &$error): void {
            $body = $b;
            $error = $e;
        });
        self::assertSame('v9.9.9', $body);
        self::assertNull($error);

        $fetcher->fetch('http://localhost/marker.txt', static function (?string $b, ?string $e) use (&$body, &$error): void {
            $body = $b;
            $error = $e;
        });
        self::assertNull($body);
        self::assertSame('refused', $error);
    }
}
