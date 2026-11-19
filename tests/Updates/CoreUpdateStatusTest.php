<?php

/**
 * CoreUpdateStatus Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Updates;

use Phlix\Shared\Updates\CoreUpdateStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Characterization of the S74/S75 exact twin (v0.57 leg 0.12, M-23).
 * Twins: phlix-server src/Server/Updates/CoreUpdateStatus.php and phlix-hub
 * src/Hub/Updates/CoreUpdateStatus.php — diff proven: namespace/prose only
 * (S74 vs S75 branding, currentVersion {@see} target). The camelCase key
 * order is the shared-parser law the admin console UI pins.
 */
#[CoversClass(CoreUpdateStatus::class)]
final class CoreUpdateStatusTest extends TestCase
{
    private function makeStatus(): CoreUpdateStatus
    {
        return new CoreUpdateStatus(
            currentVersion: '1.2.3',
            latestVersion: '1.2.4',
            updateAvailable: true,
            checkEnabled: true,
            lastCheckedAt: 1763510400,
            lastError: null,
            updateCommand: 'composer update detain/phlix-server',
        );
    }

    public function testToArrayCarriesTheExactCamelCaseKeyOrder(): void
    {
        $payload = $this->makeStatus()->toArray();

        self::assertSame(
            [
                'currentVersion',
                'latestVersion',
                'updateAvailable',
                'checkEnabled',
                'lastCheckedAt',
                'lastError',
                'updateCommand',
            ],
            array_keys($payload)
        );
        self::assertSame('1.2.3', $payload['currentVersion']);
        self::assertSame('1.2.4', $payload['latestVersion']);
        self::assertTrue($payload['updateAvailable']);
        self::assertTrue($payload['checkEnabled']);
        self::assertSame(1763510400, $payload['lastCheckedAt']);
        self::assertNull($payload['lastError']);
        self::assertSame('composer update detain/phlix-server', $payload['updateCommand']);
    }

    public function testNeverFetchedShapeSurvives(): void
    {
        $status = new CoreUpdateStatus(
            currentVersion: '1.2.3',
            latestVersion: null,
            updateAvailable: false,
            checkEnabled: false,
            lastCheckedAt: null,
            lastError: 'marker fetch failed',
            updateCommand: 'composer update',
        );

        $payload = $status->toArray();

        self::assertNull($payload['latestVersion']);
        self::assertFalse($payload['updateAvailable']);
        self::assertFalse($payload['checkEnabled']);
        self::assertNull($payload['lastCheckedAt']);
        self::assertSame('marker fetch failed', $payload['lastError']);
    }

    public function testStatusIsFinalAndImmutable(): void
    {
        // Public readonly props: set-once law (an admin poll cannot mutate a
        // rendered snapshot); final: the envelope must not be subclassed into
        // divergent shapes on either host.
        self::assertTrue((new ReflectionClass(CoreUpdateStatus::class))->isFinal());

        foreach ((new ReflectionClass(CoreUpdateStatus::class))->getProperties() as $property) {
            self::assertTrue($property->isReadOnly(), $property->getName() . ' must be readonly');
            self::assertTrue($property->isPublic(), $property->getName() . ' must stay public');
        }
    }
}
