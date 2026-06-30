<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Metadata;

use Phlix\Shared\Metadata\MetadataSourceInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Locks the {@see MetadataSourceInterface} contract: the exact method set and
 * signatures the host server's source registry (plan Step 3.5b) compiles
 * against, and that the canonical source-name accessor is implementable and
 * returns the priority-map identity string.
 *
 * @coversNothing
 */
final class MetadataSourceInterfaceTest extends TestCase
{
    public function test_is_an_interface(): void
    {
        $reflection = new ReflectionClass(MetadataSourceInterface::class);
        $this->assertTrue(
            $reflection->isInterface(),
            'MetadataSourceInterface must be an interface.'
        );
    }

    public function test_interface_declares_exactly_the_expected_methods(): void
    {
        $reflection = new ReflectionClass(MetadataSourceInterface::class);

        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods()
        );
        sort($methods);

        $this->assertSame(
            ['getDetails', 'getImages', 'search', 'sourceName', 'supportedMediaTypes'],
            $methods,
            'MetadataSourceInterface must declare exactly sourceName, supportedMediaTypes, '
            . 'search, getDetails, getImages.'
        );
    }

    public function test_sourceName_signature(): void
    {
        $method = new ReflectionMethod(MetadataSourceInterface::class, 'sourceName');
        $this->assertCount(0, $method->getParameters());
        $this->assertReturnTypeName('string', $method);
    }

    public function test_supportedMediaTypes_signature(): void
    {
        $method = new ReflectionMethod(MetadataSourceInterface::class, 'supportedMediaTypes');
        $this->assertCount(0, $method->getParameters());
        $this->assertReturnTypeName('array', $method);
    }

    public function test_search_signature(): void
    {
        $method = new ReflectionMethod(MetadataSourceInterface::class, 'search');
        $params = $method->getParameters();

        $this->assertCount(2, $params);

        $this->assertSame('query', $params[0]->getName());
        $queryType = $params[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $queryType);
        $this->assertSame('string', $queryType->getName());
        $this->assertFalse($params[0]->isOptional(), 'query must be required.');

        $this->assertSame('options', $params[1]->getName());
        $optionsType = $params[1]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $optionsType);
        $this->assertSame('array', $optionsType->getName());
        $this->assertTrue($params[1]->isOptional(), 'options must default to [].');

        $this->assertReturnTypeName('array', $method);
    }

    public function test_getDetails_signature(): void
    {
        $method = new ReflectionMethod(MetadataSourceInterface::class, 'getDetails');
        $params = $method->getParameters();

        $this->assertCount(2, $params);

        $this->assertSame('externalId', $params[0]->getName());
        $idType = $params[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $idType);
        $this->assertSame('string', $idType->getName());
        $this->assertFalse($params[0]->isOptional(), 'externalId must be required.');

        $this->assertSame('options', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional(), 'options must default to [].');

        $this->assertReturnTypeName('array', $method);
    }

    public function test_getImages_signature(): void
    {
        $method = new ReflectionMethod(MetadataSourceInterface::class, 'getImages');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('externalId', $params[0]->getName());
        $idType = $params[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $idType);
        $this->assertSame('string', $idType->getName());

        $this->assertReturnTypeName('array', $method);
    }

    public function test_interface_is_implementable_and_exposes_a_priority_map_source_name(): void
    {
        $source = $this->fakeSource();

        $this->assertInstanceOf(MetadataSourceInterface::class, $source);

        // Aligns with the anime priority map default
        // ['anidb','myanimelist','tvdb','fanart','local'] (Step 3.3a / remediation #331):
        // the name a registry keys on and the admin priority list selects by.
        $this->assertSame('anidb', $source->sourceName());
        $this->assertSame(['anime', 'series'], $source->supportedMediaTypes());
    }

    public function test_lookup_triad_round_trips_through_a_fake_implementer(): void
    {
        $source = $this->fakeSource();

        $results = $source->search('Cowboy Bebop', ['year' => 1998]);
        $this->assertSame(
            [['id' => '23', 'title' => 'Cowboy Bebop']],
            $results
        );

        $details = $source->getDetails('23');
        $this->assertSame('Cowboy Bebop', $details['title'] ?? null);
        $this->assertSame([], $source->getDetails('nope'));

        $images = $source->getImages('23');
        $this->assertSame(
            ['poster' => [['url' => 'https://img.example/23.jpg']]],
            $images
        );
        $this->assertSame([], $source->getImages('nope'));
    }

    /**
     * A minimal anonymous-class implementer proving the contract is satisfiable
     * and that PHP accepts every declared signature (including the precise
     * array shapes documented in the interface).
     */
    private function fakeSource(): MetadataSourceInterface
    {
        return new class implements MetadataSourceInterface {
            public function sourceName(): string
            {
                return 'anidb';
            }

            public function supportedMediaTypes(): array
            {
                return ['anime', 'series'];
            }

            public function search(string $query, array $options = []): array
            {
                if ($query === '') {
                    return [];
                }

                return [['id' => '23', 'title' => 'Cowboy Bebop']];
            }

            public function getDetails(string $externalId, array $options = []): array
            {
                if ($externalId !== '23') {
                    return [];
                }

                return ['title' => 'Cowboy Bebop', 'year' => 1998];
            }

            public function getImages(string $externalId): array
            {
                if ($externalId !== '23') {
                    return [];
                }

                return ['poster' => [['url' => 'https://img.example/23.jpg']]];
            }
        };
    }

    private function assertReturnTypeName(string $expected, ReflectionMethod $method): void
    {
        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame($expected, $returnType->getName());
    }
}
