<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Plugin;

use Phlix\Shared\Plugin\LifecycleInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @coversNothing
 */
final class LifecycleInterfaceTest extends TestCase
{
    public function test_interface_declares_three_methods(): void
    {
        $reflection = new ReflectionClass(LifecycleInterface::class);
        $this->assertTrue($reflection->isInterface(), 'LifecycleInterface must be an interface.');

        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods()
        );
        sort($methods);

        $this->assertSame(
            ['onDisable', 'onEnable', 'subscribedEvents'],
            $methods,
            'LifecycleInterface must declare exactly onEnable, onDisable, subscribedEvents.'
        );
    }

    public function test_onEnable_signature(): void
    {
        $method = new ReflectionMethod(LifecycleInterface::class, 'onEnable');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $type = $params[0]->getType();
        $this->assertNotNull($type);
        /** @var \ReflectionNamedType $type */
        $this->assertSame(ContainerInterface::class, $type->getName());

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        /** @var \ReflectionNamedType $returnType */
        $this->assertSame('void', $returnType->getName());
    }

    public function test_onDisable_signature(): void
    {
        $method = new ReflectionMethod(LifecycleInterface::class, 'onDisable');
        $this->assertCount(0, $method->getParameters());

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        /** @var \ReflectionNamedType $returnType */
        $this->assertSame('void', $returnType->getName());
    }

    public function test_subscribedEvents_signature(): void
    {
        $method = new ReflectionMethod(LifecycleInterface::class, 'subscribedEvents');
        $this->assertCount(0, $method->getParameters());

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        /** @var \ReflectionNamedType $returnType */
        $this->assertSame('array', $returnType->getName());
    }
}
