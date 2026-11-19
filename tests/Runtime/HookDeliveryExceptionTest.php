<?php

/**
 * HookDeliveryException Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Runtime;

use Phlix\Shared\Runtime\HookDeliveryException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Characterization of the exact twin (v0.57 leg 0.12, M-25).
 * Twins: phlix-server src/Server/Runtime/HookDeliveryException.php (@since
 * 1.2.4, S433) and phlix-hub src/Runtime/HookDeliveryException.php (@since
 * 1.3.0) — diff proven: namespace/prose only. The shared copy preserves the
 * S433 narrative: BOTH failure shapes (detected mismatch, inconclusive
 * probe) are loud — a check that cannot decide is never scored as a pass
 * (the S146 "gate that proves nothing" class).
 */
#[CoversClass(HookDeliveryException::class)]
final class HookDeliveryExceptionTest extends TestCase
{
    public function testItIsAPfinalPlainRuntimeExceptionSubclass(): void
    {
        $reflection = new ReflectionClass(HookDeliveryException::class);

        self::assertTrue($reflection->isFinal());
        $parent = $reflection->getParentClass();
        self::assertNotFalse($parent);
        self::assertSame(RuntimeException::class, $parent->getName());
        // Marker-class law: no OWN members — the TYPE is the signal callers
        // branch on (inherited Exception surface excluded).
        $owned = static fn(array $members): array => array_values(array_filter(
            $members,
            static fn($member): bool => $member->getDeclaringClass()->getName() === HookDeliveryException::class
        ));

        self::assertSame([], $owned($reflection->getMethods()));
        self::assertSame([], $owned($reflection->getProperties()));
        self::assertSame([], $owned($reflection->getConstants()));
    }

    public function testMessageAndCodeTravelFromTheThrowSite(): void
    {
        $exception = new HookDeliveryException('hook allowlist probe inconclusive', 42);

        self::assertSame('hook allowlist probe inconclusive', $exception->getMessage());
        self::assertSame(42, $exception->getCode());
    }
}
