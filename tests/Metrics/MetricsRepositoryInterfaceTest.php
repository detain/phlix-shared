<?php

/**
 * MetricsRepositoryInterface Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Metrics;

use Phlix\Shared\Metrics\MetricsRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Characterization of the S1/S4 exact twin (v0.57 leg 0.12, M-24).
 * Twins: phlix-server src/Stats/Metrics/MetricsRepositoryInterface.php and
 * phlix-hub src/Stats/Metrics/MetricsRepositoryInterface.php — diff proven:
 * namespace/prose only (one line says "server-wide", the other "hub-wide").
 * The read-side contract the admin metrics panels consume.
 */
#[CoversNothing]
final class MetricsRepositoryInterfaceTest extends TestCase
{
    public function testContractIsTheFourReadMethodsWithNoExtras(): void
    {
        $methods = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(MetricsRepositoryInterface::class))->getMethods()
        );

        self::assertSame(['snapshot', 'history', 'liveConnections', 'topRoutes'], $methods);
    }

    public function testDefaultWindowArgumentsMatchTheShippedTwins(): void
    {
        $class = new ReflectionClass(MetricsRepositoryInterface::class);

        self::assertSame([60], self::defaults($class->getMethod('snapshot')));
        self::assertSame([60, 60], self::defaults($class->getMethod('history')));
        self::assertSame([10], self::defaults($class->getMethod('liveConnections')));
        self::assertSame([15, 20], self::defaults($class->getMethod('topRoutes')));
    }

    public function testEveryMethodReturnsArrayShapedPayloads(): void
    {
        foreach ((new ReflectionClass(MetricsRepositoryInterface::class))->getMethods() as $method) {
            self::assertSame('array', (string) $method->getReturnType(), $method->getName());
        }
    }

    public function testAFakeSatisfiesTheWholeContractForPanelCode(): void
    {
        $repository = new class implements MetricsRepositoryInterface {
            public function snapshot(int $windowSeconds = 60): array
            {
                return [
                    'bytes_in_per_sec' => 0.0,
                    'bytes_out_per_sec' => 0.0,
                    'active_connections' => 0,
                    'requests_per_sec' => 0.0,
                    'error_rate' => 0.0,
                    'p50_ms' => 0,
                    'p95_ms' => 0,
                    'p99_ms' => 0,
                ];
            }

            public function history(int $minutes = 60, int $resolutionSeconds = 60): array
            {
                return [];
            }

            public function liveConnections(int $ttlSeconds = 10): array
            {
                return [];
            }

            public function topRoutes(int $minutes = 15, int $limit = 20): array
            {
                return [];
            }
        };

        self::assertArrayHasKey('bytes_in_per_sec', $repository->snapshot());
        self::assertSame([], $repository->history());
        self::assertSame([], $repository->liveConnections());
        self::assertSame([], $repository->topRoutes());
    }

    /**
     * @return list<mixed>
     */
    private static function defaults(ReflectionMethod $method): array
    {
        return array_map(
            static fn($parameter): mixed => $parameter->getDefaultValue(),
            $method->getParameters()
        );
    }
}
