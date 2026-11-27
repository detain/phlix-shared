<?php

/**
 * Arr Client Factory Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Arr;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phlix\Shared\Arr\AbstractArrClient;
use Phlix\Shared\Arr\ArrClientFactory;
use Phlix\Shared\Arr\SonarrClient;
use Phlix\Shared\Arr\RadarrClient;
use Phlix\Shared\Arr\Transport\ArrTransportInterface;
use Phlix\Shared\Arr\Transport\CurlArrTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * Unit tests for ArrClientFactory.
 *
 * @package Phlix\Tests\Unit\Arr
 * @since 0.12.0
 */
class ArrClientFactoryTest extends TestCase
{
    public function testCreatesSonarrClientWithConfig(): void
    {
        $config = [
            'sonarr' => [
                'url' => 'http://sonarr.local:8989',
                'api_key' => 'sonarr-api-key-123',
                'enabled' => true,
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createSonarrClient();

        $this->assertInstanceOf(SonarrClient::class, $client);
    }

    public function testCreatesRadarrClientWithConfig(): void
    {
        $config = [
            'radarr' => [
                'url' => 'http://radarr.local:7878',
                'api_key' => 'radarr-api-key-456',
                'enabled' => true,
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createRadarrClient();

        $this->assertInstanceOf(RadarrClient::class, $client);
    }

    public function testReturnsNullWhenSonarrNotEnabled(): void
    {
        $config = [
            'sonarr' => [
                'url' => 'http://localhost:8989',
                'api_key' => 'some-key',
                'enabled' => false,
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createSonarrClient();

        $this->assertNull($client);
    }

    public function testReturnsNullWhenRadarrNotEnabled(): void
    {
        $config = [
            'radarr' => [
                'url' => 'http://localhost:7878',
                'api_key' => 'some-key',
                'enabled' => false,
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createRadarrClient();

        $this->assertNull($client);
    }

    public function testReturnsNullWhenSonarrApiKeyEmpty(): void
    {
        $config = [
            'sonarr' => [
                'url' => 'http://localhost:8989',
                'api_key' => '',
                'enabled' => true,
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createSonarrClient();

        $this->assertNull($client);
    }

    public function testReturnsNullWhenRadarrApiKeyEmpty(): void
    {
        $config = [
            'radarr' => [
                'url' => 'http://localhost:7878',
                'api_key' => '',
                'enabled' => true,
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createRadarrClient();

        $this->assertNull($client);
    }

    public function testReturnsNullWhenSonarrConfigMissing(): void
    {
        $config = [];

        $factory = new ArrClientFactory($config);
        $client = $factory->createSonarrClient();

        $this->assertNull($client);
    }

    public function testReturnsNullWhenRadarrConfigMissing(): void
    {
        $config = [];

        $factory = new ArrClientFactory($config);
        $client = $factory->createRadarrClient();

        $this->assertNull($client);
    }

    public function testCreatesBothClientsSimultaneously(): void
    {
        $config = [
            'sonarr' => [
                'url' => 'http://sonarr.local:8989',
                'api_key' => 'sonarr-key',
                'enabled' => true,
            ],
            'radarr' => [
                'url' => 'http://radarr.local:7878',
                'api_key' => 'radarr-key',
                'enabled' => true,
            ],
        ];

        $factory = new ArrClientFactory($config);

        $sonarrClient = $factory->createSonarrClient();
        $radarrClient = $factory->createRadarrClient();

        $this->assertInstanceOf(SonarrClient::class, $sonarrClient);
        $this->assertInstanceOf(RadarrClient::class, $radarrClient);
    }

    public function testDefaultValuesWhenConfigPartial(): void
    {
        $config = [
            'sonarr' => [
                'enabled' => true,
                // url and api_key missing - should use defaults
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createSonarrClient();

        // With empty API key, should return null
        $this->assertNull($client);
    }

    public function testMisconfiguredIsEmptyWhenNoSectionExists(): void
    {
        $factory = new ArrClientFactory([]);

        $this->assertSame([], $factory->misconfigured());
    }

    public function testMisconfiguredIsEmptyWhenSectionIsDisabledEvenWithoutKey(): void
    {
        $factory = new ArrClientFactory([
            'sonarr' => ['enabled' => false, 'api_key' => ''],
            'radarr' => ['enabled' => false],
        ]);

        $this->assertSame([], $factory->misconfigured());
    }

    public function testMisconfiguredNamesSonarrWhenEnabledWithEmptyKey(): void
    {
        $factory = new ArrClientFactory([
            'sonarr' => ['url' => 'http://localhost:8989', 'api_key' => '', 'enabled' => true],
        ]);

        $this->assertSame(['sonarr'], $factory->misconfigured());
    }

    public function testMisconfiguredNamesSonarrWhenEnabledWithKeyAbsent(): void
    {
        $factory = new ArrClientFactory([
            'sonarr' => ['url' => 'http://localhost:8989', 'enabled' => true],
        ]);

        $this->assertSame(['sonarr'], $factory->misconfigured());
    }

    public function testMisconfiguredNamesRadarrWhenEnabledWithoutKey(): void
    {
        $factory = new ArrClientFactory([
            'radarr' => ['url' => 'http://localhost:7878', 'api_key' => '', 'enabled' => true],
        ]);

        $this->assertSame(['radarr'], $factory->misconfigured());
    }

    public function testMisconfiguredNamesBothInFixedOrder(): void
    {
        $factory = new ArrClientFactory([
            'radarr' => ['api_key' => '', 'enabled' => true],
            'sonarr' => ['enabled' => true],
        ]);

        $this->assertSame(['sonarr', 'radarr'], $factory->misconfigured());
    }

    public function testMisconfiguredIsEmptyWhenEnabledSectionsCarryKeys(): void
    {
        $factory = new ArrClientFactory([
            'sonarr' => ['api_key' => 'sonarr-key', 'enabled' => true],
            'radarr' => ['api_key' => 'radarr-key', 'enabled' => true],
        ]);

        $this->assertSame([], $factory->misconfigured());
    }

    /**
     * @return iterable<string, array{
     *     array{sonarr?: array{url?: string, api_key?: string, enabled?: bool, connect_timeout?: int|string|null},
     *           radarr?: array{url?: string, api_key?: string, enabled?: bool, connect_timeout?: int|string|null}},
     *     bool, bool, list<string>
     * }>
     */
    public static function detectorCreatorEquivalenceMatrix(): iterable
    {
        yield 'nothing configured' => [[], true, true, []];
        yield 'sonarr disabled with key' => [
            ['sonarr' => ['enabled' => false, 'api_key' => 'k']],
            true,
            true,
            [],
        ];
        yield 'sonarr disabled without key' => [
            ['sonarr' => ['enabled' => false, 'api_key' => '']],
            true,
            true,
            [],
        ];
        yield 'sonarr enabled without key' => [
            ['sonarr' => ['enabled' => true, 'api_key' => '']],
            true,
            true,
            ['sonarr'],
        ];
        yield 'sonarr enabled with key' => [
            ['sonarr' => ['enabled' => true, 'api_key' => 'k']],
            false,
            true,
            [],
        ];
        yield 'sonarr enabled with zero-string key' => [
            ['sonarr' => ['enabled' => true, 'api_key' => '0']],
            false,
            true,
            [],
        ];
        yield 'radarr enabled with zero-string key' => [
            ['radarr' => ['enabled' => true, 'api_key' => '0']],
            true,
            false,
            [],
        ];
        yield 'radarr enabled without key' => [
            ['radarr' => ['enabled' => true, 'api_key' => '']],
            true,
            true,
            ['radarr'],
        ];
        yield 'both enabled without keys' => [
            [
                'sonarr' => ['enabled' => true, 'api_key' => ''],
                'radarr' => ['enabled' => true],
            ],
            true,
            true,
            ['sonarr', 'radarr'],
        ];
        yield 'both enabled with keys' => [
            [
                'sonarr' => ['enabled' => true, 'api_key' => 's'],
                'radarr' => ['enabled' => true, 'api_key' => 'r'],
            ],
            false,
            false,
            [],
        ];
    }

    /**
     * The null a creator returns must mean exactly "disabled OR named by
     * misconfigured()" — detector and branch can never drift apart (S-shared-20).
     * The zero-string rows are the in-contract coercion sentinels: '0' is a
     * falsy-yet-non-empty key, so restating either side with falsy coercion
     * (`!$apiKey`) instead of the shared strict law convicts here (r1 minor).
     *
     * @param array{sonarr?: array{url?: string, api_key?: string, enabled?: bool, connect_timeout?: int|string|null},
     *               radarr?: array{url?: string, api_key?: string, enabled?: bool, connect_timeout?: int|string|null}} $config
     * @param list<string> $expectedMisconfigured
     */
    #[DataProvider('detectorCreatorEquivalenceMatrix')]
    public function testTheDetectorAndTheCreatorsNeverDisagree(
        array $config,
        bool $sonarrExpectNull,
        bool $radarrExpectNull,
        array $expectedMisconfigured
    ): void {
        $factory = new ArrClientFactory($config);

        $this->assertSame($sonarrExpectNull, $factory->createSonarrClient() === null);
        $this->assertSame($radarrExpectNull, $factory->createRadarrClient() === null);
        $this->assertSame($expectedMisconfigured, $factory->misconfigured());
    }

    /**
     * Coercion sentinels for the ONE shared branch law (S-shared-20 r1 minor).
     *
     * These api_key shapes sit outside the documented string contract, so the
     * pin runs through the detector window: the creators call this very same
     * predicate, but constructing a client with a bool or int key dies in
     * AbstractArrClient's strict string parameter — a constructor law, not a
     * branch law, and the matrix above never feeds it such shapes. Emptiness
     * must stay strict: on PHP 8 `0 == ''` is already false, so the `== ''`
     * mutation of the shared law diverges from `=== ''` exactly on bool false
     * — this is the row that convicts a loose-comparison "simplification" of
     * the one predicate the detector and the creators now literally share.
     *
     * @return iterable<string, array{array<string, array<string, mixed>>, list<string>}>
     */
    public static function branchLawCoercionSentinels(): iterable
    {
        yield 'bool false key is present, not empty' => [
            ['sonarr' => ['enabled' => true, 'api_key' => false]],
            [],
        ];
        yield 'int zero key is present, not empty' => [
            ['radarr' => ['enabled' => true, 'api_key' => 0]],
            [],
        ];
        yield 'null key coalesces to absent' => [
            ['sonarr' => ['enabled' => true, 'api_key' => null]],
            ['sonarr'],
        ];
    }

    /**
     * $config carries the constructor's own declared input shape. The sentinel
     * datasets deliberately violate it (non-string api_key, nonsense
     * connect_timeout) to probe the coercion arms, reaching the body unchecked
     * through the untyped data-provider boundary — the constructor tolerates
     * that by design.
     *
     * @param array{sonarr?: array{url?: string, api_key?: string, enabled?: bool, connect_timeout?: int|string|null}, radarr?: array{url?: string, api_key?: string, enabled?: bool, connect_timeout?: int|string|null}} $config                 Client map under test.
     * @param list<string>                                                                                                                                                                                               $expectedMisconfigured Exactly which sections must be reported.
     */
    #[DataProvider('branchLawCoercionSentinels')]
    public function testTheSharedBranchLawComparesEmptinessStrictly(array $config, array $expectedMisconfigured): void
    {
        $this->assertSame($expectedMisconfigured, (new ArrClientFactory($config))->misconfigured());
    }

    /**
     * Garbage connect_timeout on a section that can never yield a client is
     * inert: the knob is parsed only after both null-branches pass.
     */
    public function testGarbageConnectTimeoutStaysInertOnUnusableSections(): void
    {
        $factory = new ArrClientFactory([
            'sonarr' => ['enabled' => false, 'connect_timeout' => 'nonsense'],
            'radarr' => ['enabled' => true, 'api_key' => '', 'connect_timeout' => -1],
        ]);

        $this->assertNull($factory->createSonarrClient());
        $this->assertNull($factory->createRadarrClient());
        $this->assertSame(['radarr'], $factory->misconfigured());
    }

    public function testSonarrConnectTimeoutIsForwardedToTheDefaultTransport(): void
    {
        $factory = new ArrClientFactory([
            'sonarr' => ['enabled' => true, 'api_key' => 'k', 'connect_timeout' => 2],
        ]);

        $client = $factory->createSonarrClient();
        $this->assertNotNull($client);

        $transport = $this->defaultTransportOf($client);
        $this->assertSame(2, $this->intProperty($transport, 'connectTimeout'));
        $this->assertSame(30, $this->intProperty($transport, 'timeout'));
    }

    public function testRadarrConnectTimeoutIsForwardedToTheDefaultTransport(): void
    {
        $factory = new ArrClientFactory([
            'radarr' => ['enabled' => true, 'api_key' => 'k', 'connect_timeout' => 7],
        ]);

        $client = $factory->createRadarrClient();
        $this->assertNotNull($client);

        $this->assertSame(7, $this->intProperty($this->defaultTransportOf($client), 'connectTimeout'));
    }

    public function testAbsentConnectTimeoutLeavesTheClientDefaultStanding(): void
    {
        $factory = new ArrClientFactory([
            'sonarr' => ['enabled' => true, 'api_key' => 'k'],
        ]);

        $client = $factory->createSonarrClient();
        $this->assertNotNull($client);

        $this->assertSame(5, $this->intProperty($this->defaultTransportOf($client), 'connectTimeout'));
    }

    public function testExplicitNullConnectTimeoutLeavesTheClientDefaultStanding(): void
    {
        $factory = new ArrClientFactory([
            'sonarr' => ['enabled' => true, 'api_key' => 'k', 'connect_timeout' => null],
        ]);

        $client = $factory->createSonarrClient();
        $this->assertNotNull($client);

        $this->assertSame(5, $this->intProperty($this->defaultTransportOf($client), 'connectTimeout'));
    }

    public function testDigitStringConnectTimeoutIsAdmittedForEnvBackedConfig(): void
    {
        $factory = new ArrClientFactory([
            'sonarr' => ['enabled' => true, 'api_key' => 'k', 'connect_timeout' => '3'],
        ]);

        $client = $factory->createSonarrClient();
        $this->assertNotNull($client);

        $this->assertSame(3, $this->intProperty($this->defaultTransportOf($client), 'connectTimeout'));
    }

    public function testInjectedTransportIsNeverReplacedEvenWithConnectTimeoutSet(): void
    {
        $transport = new class implements ArrTransportInterface {
            /** @return array{status:int, body:string} */
            public function request(string $method, string $url, array $headers, ?string $body): array
            {
                return ['status' => 200, 'body' => ''];
            }
        };

        $factory = new ArrClientFactory(
            ['sonarr' => ['enabled' => true, 'api_key' => 'k', 'connect_timeout' => 2]],
            $transport
        );

        $client = $factory->createSonarrClient();
        $this->assertNotNull($client);

        // The knob reaches the DEFAULT transport only; injected transports own
        // their timing and the factory must not build a second one behind them.
        $reflected = (new ReflectionClass(AbstractArrClient::class))->getProperty('transport')->getValue($client);
        $this->assertSame($transport, $reflected);
    }

    /**
     * In-contract values (the constructor's shape admits int and string, or
     * null) that must still fail the positivity/digit law at runtime. The
     * decimal/exponent strings are the coercion traps: a naive `(int) $raw`
     * or is_numeric() gate would silently ACCEPT '2.5' as 2 and '1e3' as
     * 1000, so their rejection pins the guard's real teeth.
     *
     * @return iterable<string, array{int|string}>
     */
    public static function invalidConnectTimeoutValues(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'digit-zero string' => ['0'];
        yield 'negative digit string' => ['-2'];
        yield 'non-numeric string' => ['abc'];
        yield 'empty string' => [''];
        yield 'decimal string' => ['2.5'];
        yield 'exponent string' => ['1e3'];
        yield 'space-padded digit' => [' 5'];
    }

    #[DataProvider('invalidConnectTimeoutValues')]
    public function testGarbageConnectTimeoutOnAUsableSectionFailsLoud(int|string $raw): void
    {
        $factory = new ArrClientFactory([
            'sonarr' => ['enabled' => true, 'api_key' => 'k', 'connect_timeout' => $raw],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sonarr connect_timeout must be a positive integer');

        $factory->createSonarrClient();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonScalarConnectTimeoutValues(): iterable
    {
        yield 'float' => [2.5];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'array' => [[]];
        yield 'object' => [new \stdClass()];
    }

    /**
     * Out-of-contract non-scalar types (a mis-parsed env layer handing over a
     * bool where `(int) true` would smuggle a 1-second timeout through) are
     * rejected by the guard, never coerced. Exercised directly against the
     * parser because the constructor's documented shape — correctly — does
     * not admit these types as inputs.
     */
    #[DataProvider('nonScalarConnectTimeoutValues')]
    public function testNonScalarConnectTimeoutIsRejectedNotCoerced(mixed $raw): void
    {
        $resolve = new \ReflectionMethod(ArrClientFactory::class, 'resolveConnectTimeout');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sonarr connect_timeout must be a positive integer');

        $resolve->invoke(null, ['connect_timeout' => $raw], 'sonarr');
    }

    public function testGarbageRadarrConnectTimeoutNamesRadarrInTheError(): void
    {
        $factory = new ArrClientFactory([
            'radarr' => ['enabled' => true, 'api_key' => 'k', 'connect_timeout' => 'soon'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('radarr connect_timeout must be a positive integer');

        $factory->createRadarrClient();
    }

    /**
     * Anti-rot pin (S-shared-20): the factory docblock must carry the boot
     * posture truth — hub's DI builds this factory with no transport, so the
     * blocking default is NOT filed under a bare CLI/test-only label, and the
     * detector for the null-branch conflation must be documented.
     */
    public function testTheFactoryDocblockCarriesBootPostureTruth(): void
    {
        $doc = (string) (new ReflectionClass(ArrClientFactory::class))->getDocComment();

        $this->assertStringContainsString('phlix-shared-021', $doc);
        $this->assertStringContainsString('RUNTIME TRUTH', $doc);
        $this->assertStringContainsString('misconfigured()', $doc);
        $this->assertStringNotContainsString('CLI/test', $doc);
        $this->assertStringNotContainsString('SWOOLE_HOOK_ALL', $doc);
    }

    private function defaultTransportOf(object $client): CurlArrTransport
    {
        $transport = (new ReflectionClass(AbstractArrClient::class))->getProperty('transport')->getValue($client);

        $this->assertInstanceOf(CurlArrTransport::class, $transport);

        return $transport;
    }

    private function intProperty(object $target, string $name): int
    {
        $value = (new ReflectionClass($target))->getProperty($name)->getValue($target);

        $this->assertIsInt($value);

        return $value;
    }
}
