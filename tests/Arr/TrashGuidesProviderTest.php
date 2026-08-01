<?php

/**
 * Trash Guides Provider Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Arr;

use PHPUnit\Framework\TestCase;
use Phlix\Shared\Arr\TrashGuidesProvider;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for TrashGuidesProvider.
 *
 * @package Phlix\Tests\Unit\Arr
 * @since 0.4.0
 */
class TrashGuidesProviderTest extends TestCase
{
    public function testDefaultConfigUrlsUseTrashGuidesOrg(): void
    {
        $provider = new TrashGuidesProvider();

        $reflection = new \ReflectionClass($provider);
        $loadConfig = $reflection->getMethod('loadConfig');
        $loadConfig->setAccessible(true);

        /** @var array<string, mixed> $config */
        $config = $loadConfig->invoke($provider);

        $this->assertIsString($config['custom_formats_url']);
        $this->assertIsString($config['quality_profiles_url']);

        // The org path segment must be `TRaSH-Guides/Guides`, never the
        // malformed `TRaSH-/Guides`.
        $this->assertStringContainsString('TRaSH-Guides/Guides', $config['custom_formats_url']);
        $this->assertStringContainsString('TRaSH-Guides/Guides', $config['quality_profiles_url']);
        $this->assertStringNotContainsString('TRaSH-/Guides', $config['custom_formats_url']);
        $this->assertStringNotContainsString('TRaSH-/Guides', $config['quality_profiles_url']);

        $this->assertSame(
            'https://raw.githubusercontent.com/TRaSH-Guides/Guides/main/docs/json/radarr/'
                . 'radarr-collection-of-custom-formats.json',
            $config['custom_formats_url']
        );
        $this->assertSame(
            'https://raw.githubusercontent.com/TRaSH-Guides/Guides/main/docs/json/radarr/'
                . 'radarr-setup-quality-profiles-parent.json',
            $config['quality_profiles_url']
        );
    }

    public function testConstructorAcceptsLogger(): void
    {
        // Test that logger parameter is accepted without error
        $logger = $this->createMock(LoggerInterface::class);
        $provider = new TrashGuidesProvider($logger);

        $this->assertInstanceOf(TrashGuidesProvider::class, $provider);
    }

    public function testConstructorWithoutLogger(): void
    {
        $provider = new TrashGuidesProvider();

        $this->assertInstanceOf(TrashGuidesProvider::class, $provider);
    }

    public function testSyncResultConstructor(): void
    {
        $result = new \Phlix\Shared\Arr\SyncResult(
            customFormatsAdded: 5,
            customFormatsUpdated: 2,
            qualityProfilesAdded: 1,
            qualityProfilesUpdated: 3,
            version: 'abc123',
            syncedAt: new \DateTimeImmutable()
        );

        $this->assertEquals(5, $result->customFormatsAdded);
        $this->assertEquals(2, $result->customFormatsUpdated);
        $this->assertEquals(1, $result->qualityProfilesAdded);
        $this->assertEquals(3, $result->qualityProfilesUpdated);
        $this->assertEquals('abc123', $result->version);
    }

    public function testSyncResultGetTotalChanges(): void
    {
        $result = new \Phlix\Shared\Arr\SyncResult(
            customFormatsAdded: 5,
            customFormatsUpdated: 2,
            qualityProfilesAdded: 1,
            qualityProfilesUpdated: 3,
            version: 'abc123',
            syncedAt: new \DateTimeImmutable()
        );

        $this->assertEquals(11, $result->getTotalChanges());
    }

    public function testSyncResultGetTotalCustomFormatsChanged(): void
    {
        $result = new \Phlix\Shared\Arr\SyncResult(
            customFormatsAdded: 5,
            customFormatsUpdated: 2,
            qualityProfilesAdded: 0,
            qualityProfilesUpdated: 0,
            version: 'abc123',
            syncedAt: new \DateTimeImmutable()
        );

        $this->assertEquals(7, $result->getTotalCustomFormatsChanged());
    }

    public function testSyncResultGetTotalQualityProfilesChanged(): void
    {
        $result = new \Phlix\Shared\Arr\SyncResult(
            customFormatsAdded: 0,
            customFormatsUpdated: 0,
            qualityProfilesAdded: 3,
            qualityProfilesUpdated: 2,
            version: 'abc123',
            syncedAt: new \DateTimeImmutable()
        );

        $this->assertEquals(5, $result->getTotalQualityProfilesChanged());
    }

    public function testSyncResultIsEmptyWhenNoChanges(): void
    {
        $result = new \Phlix\Shared\Arr\SyncResult(
            customFormatsAdded: 0,
            customFormatsUpdated: 0,
            qualityProfilesAdded: 0,
            qualityProfilesUpdated: 0,
            version: 'abc123',
            syncedAt: new \DateTimeImmutable()
        );

        $this->assertTrue($result->isEmpty());
    }

    public function testSyncResultIsNotEmptyWhenChanges(): void
    {
        $result = new \Phlix\Shared\Arr\SyncResult(
            customFormatsAdded: 1,
            customFormatsUpdated: 0,
            qualityProfilesAdded: 0,
            qualityProfilesUpdated: 0,
            version: 'abc123',
            syncedAt: new \DateTimeImmutable()
        );

        $this->assertFalse($result->isEmpty());
    }

    public function testSyncResultToArray(): void
    {
        $syncedAt = new \DateTimeImmutable('2024-01-15 12:00:00');
        $result = new \Phlix\Shared\Arr\SyncResult(
            customFormatsAdded: 5,
            customFormatsUpdated: 2,
            qualityProfilesAdded: 1,
            qualityProfilesUpdated: 3,
            version: 'abc123',
            syncedAt: $syncedAt
        );

        $array = $result->toArray();

        $this->assertEquals(5, $array['custom_formats_added']);
        $this->assertEquals(2, $array['custom_formats_updated']);
        $this->assertEquals(1, $array['quality_profiles_added']);
        $this->assertEquals(3, $array['quality_profiles_updated']);
        $this->assertEquals('abc123', $array['version']);
        $this->assertEquals(11, $array['total_changes']);
        $this->assertIsString($array['synced_at']);
        $this->assertStringContainsString('2024-01-15', $array['synced_at']);
    }

    /**
     * @dataProvider provideValidJsonStrings
     */
    public function testParseJsonAcceptsValidJson(string $json): void
    {
        $provider = new TrashGuidesProvider();

        $reflection = new \ReflectionClass($provider);
        $parseJson = $reflection->getMethod('parseJson');
        $parseJson->setAccessible(true);

        $result = $parseJson->invoke($provider, $json);

        $this->assertIsArray($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideValidJsonStrings(): array
    {
        return [
            'empty object' => ['{}'],
            'simple object' => ['{"key":"value"}'],
            'nested object' => ['{"outer":{"inner":"value"}}'],
            'array' => ['[1,2,3]'],
            'complex structure' => ['{"version":"1.0","profiles":[{"name":"HD"}]}'],
        ];
    }

    public function testParseJsonRejectsInvalidJson(): void
    {
        $provider = new TrashGuidesProvider();

        $reflection = new \ReflectionClass($provider);
        $parseJson = $reflection->getMethod('parseJson');
        $parseJson->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON response');
        $parseJson->invoke($provider, '{not json');
    }

    public function testParseJsonRejectsNonArrayResult(): void
    {
        $provider = new TrashGuidesProvider();

        $reflection = new \ReflectionClass($provider);
        $parseJson = $reflection->getMethod('parseJson');
        $parseJson->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON response');
        $parseJson->invoke($provider, '"just a string"');
    }

    /**
     * @dataProvider provideUrlsForVersionDerivation
     */
    public function testDeriveVersionFromUrl(string $url, string $expectedPattern): void
    {
        $provider = new TrashGuidesProvider();

        $reflection = new \ReflectionClass($provider);
        $deriveVersionFromUrl = $reflection->getMethod('deriveVersionFromUrl');
        $deriveVersionFromUrl->setAccessible(true);

        /** @var string $version */
        $version = $deriveVersionFromUrl->invoke($provider, $url);

        $this->assertMatchesRegularExpression($expectedPattern, $version);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideUrlsForVersionDerivation(): array
    {
        return [
            'commit SHA in path' => [
                'https://raw.githubusercontent.com/TRaSH-Guides/Guides/abc123def456/docs/json/radarr/file.json',
                '/^[a-f0-9]{7,40}$/',
            ],
            'main branch' => [
                'https://raw.githubusercontent.com/TRaSH-Guides/Guides/main/docs/json/radarr/file.json',
                '/^unknown-\d+$/',
            ],
            'no match returns unknown with timestamp' => [
                'https://example.com/no-match-here.json',
                '/^unknown-\d+$/',
            ],
        ];
    }

    public function testClearCacheResetsAllCaches(): void
    {
        $provider = new TrashGuidesProvider();

        $reflection = new \ReflectionClass($provider);

        // Set up the static caches via reflection
        $qualityProfilesCache = $reflection->getProperty('qualityProfilesCache');
        $qualityProfilesCache->setAccessible(true);
        $qualityProfilesCache->setValue(null, ['cached' => 'data']);

        $customFormatsCache = $reflection->getProperty('customFormatsCache');
        $customFormatsCache->setAccessible(true);
        $customFormatsCache->setValue(null, ['cached' => 'format']);

        $versionCache = $reflection->getProperty('versionCache');
        $versionCache->setAccessible(true);
        $versionCache->setValue(null, 'abc123');

        $cacheTimestamp = $reflection->getProperty('cacheTimestamp');
        $cacheTimestamp->setAccessible(true);
        $cacheTimestamp->setValue(null, time());

        // Set instance caches
        $qualityProfiles = $reflection->getProperty('qualityProfiles');
        $qualityProfiles->setAccessible(true);
        $qualityProfiles->setValue($provider, ['instance' => 'data']);

        $customFormats = $reflection->getProperty('customFormats');
        $customFormats->setAccessible(true);
        $customFormats->setValue($provider, ['instance' => 'format']);

        $version = $reflection->getProperty('version');
        $version->setAccessible(true);
        $version->setValue($provider, 'v1');

        // Call clearCache
        $provider->clearCache();

        // Verify static caches are cleared
        $this->assertNull($qualityProfilesCache->getValue());
        $this->assertNull($customFormatsCache->getValue());
        $this->assertNull($versionCache->getValue());
        $this->assertNull($cacheTimestamp->getValue());

        // Verify instance caches are cleared
        $this->assertNull($qualityProfiles->getValue($provider));
        $this->assertNull($customFormats->getValue($provider));
        $this->assertNull($version->getValue($provider));
    }
}
