<?php

/**
 * Configurable Interface Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Plugin;

use Phlix\Shared\Plugin\ConfigurableInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @coversNothing
 */
final class ConfigurableInterfaceTest extends TestCase
{
    public function test_interface_declares_only_configure(): void
    {
        $reflection = new ReflectionClass(ConfigurableInterface::class);
        $this->assertTrue($reflection->isInterface(), 'ConfigurableInterface must be an interface.');

        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods()
        );

        $this->assertSame(['configure'], $methods, 'ConfigurableInterface must declare exactly configure().');
    }

    public function test_configure_signature(): void
    {
        $method = new ReflectionMethod(ConfigurableInterface::class, 'configure');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('settings', $params[0]->getName());

        $type = $params[0]->getType();
        $this->assertNotNull($type);
        /** @var \ReflectionNamedType $type */
        $this->assertSame('array', $type->getName());

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        /** @var \ReflectionNamedType $returnType */
        $this->assertSame('void', $returnType->getName());
    }

    public function test_a_class_can_implement_it(): void
    {
        $impl = new class implements ConfigurableInterface {
            /** @var array<string, mixed> */
            public array $received = [];

            public function configure(array $settings): void
            {
                $this->received = $settings;
            }
        };

        $impl->configure(['api_key' => 'abc', 'enabled' => true]);
        $this->assertSame(['api_key' => 'abc', 'enabled' => true], $impl->received);
    }
}
