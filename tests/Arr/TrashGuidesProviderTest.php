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

    /**
     * M-SHARED-22: a poisoned config must not turn the fetch into a local
     * stream read. The https-only guard fires before any stream call, so
     * `file://` (which would SUCCEED against a real local file!) is refused
     * deterministically and offline.
     */
    public function testFetchUrlRefusesFileSchemeBeforeAnyRead(): void
    {
        $provider   = new TrashGuidesProvider();
        $reflection = new \ReflectionClass($provider);
        $fetchUrl   = $reflection->getMethod('fetchUrl');
        $fetchUrl->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only https:// URLs are allowed (got scheme "file")');

        // /etc/passwd exists on every CI venue; the OLD code happily read and
        // returned it. The test only passes if the guard refuses first.
        $fetchUrl->invoke($provider, 'file:///etc/passwd');
    }

    /**
     * M-SHARED-22: plain http is refused too (the TRaSH-Guides upstream is
     * https-only; no legitimate caller loses anything).
     */
    public function testFetchUrlRefusesHttpScheme(): void
    {
        $provider   = new TrashGuidesProvider();
        $reflection = new \ReflectionClass($provider);
        $fetchUrl   = $reflection->getMethod('fetchUrl');
        $fetchUrl->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only https:// URLs are allowed (got scheme "http")');

        $fetchUrl->invoke($provider, 'http://raw.githubusercontent.com/x/y.json');
    }

    /**
     * M-SHARED-22: a scheme-less path is not a fetchable URL either.
     */
    public function testFetchUrlRefusesSchemelessPath(): void
    {
        $provider   = new TrashGuidesProvider();
        $reflection = new \ReflectionClass($provider);
        $fetchUrl   = $reflection->getMethod('fetchUrl');
        $fetchUrl->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only https:// URLs are allowed (got scheme "none")');

        $fetchUrl->invoke($provider, '/etc/shadow');
    }

    /**
     * LW-053 [review shared__srphp Major #1]: the instance memo must not
     * bypass the TTL. Red-first at the parent commit: the memo check ran
     * BEFORE ensureCacheValid(), so a populated memo was returned forever
     * and the fetch leg never re-ran — this test's fail() line fires with
     * the pinned generation instead.
     *
     * The refetch attempt is made deterministically offline by unregistering
     * the https stream wrapper for the duration of the call: the observable
     * is the RuntimeException from the fetch leg (proof the ladder re-ran),
     * never a real socket. The aged timestamp IS the injected clock — the
     * TTL comparison reads it against hrtime() on every call.
     */
    public function testQualityProfilesMemoRefetchesAfterTtlExpiry(): void
    {
        $provider = $this->primeAgedProvider('qualityProfiles');

        $fetchAttempted = false;
        self::assertTrue(stream_wrapper_unregister('https'));
        try {
            $provider->getQualityProfiles();
        } catch (\RuntimeException $e) {
            // The fetch leg re-ran and died on the deliberately unregistered
            // wrapper — that is the post-fix behavior under test.
            $this->assertStringContainsString('Failed to fetch TRaSH-Guides data', $e->getMessage());
            $fetchAttempted = true;
        } finally {
            self::assertTrue(stream_wrapper_restore('https'));
        }

        self::assertTrue(
            $fetchAttempted,
            'TTL expiry must dislodge the instance memo and re-run the fetch (LW-053);'
                . ' the pinned memo was returned instead.'
        );

        $this->assertMemoReleasedAndStaticsCleared($provider, 'qualityProfiles');
    }

    /**
     * LW-053: same law on the custom-formats ladder.
     */
    public function testCustomFormatsMemoRefetchesAfterTtlExpiry(): void
    {
        $provider = $this->primeAgedProvider('customFormats');

        $fetchAttempted = false;
        self::assertTrue(stream_wrapper_unregister('https'));
        try {
            $provider->getCustomFormats();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to fetch TRaSH-Guides data', $e->getMessage());
            $fetchAttempted = true;
        } finally {
            self::assertTrue(stream_wrapper_restore('https'));
        }

        self::assertTrue(
            $fetchAttempted,
            'TTL expiry must dislodge the instance memo and re-run the fetch (LW-053);'
                . ' the pinned memo was returned instead.'
        );

        $this->assertMemoReleasedAndStaticsCleared($provider, 'customFormats');
    }

    /**
     * LW-053: same law on the version ladder (its fetch leg rides the
     * custom-formats URL).
     */
    public function testVersionMemoRefetchesAfterTtlExpiry(): void
    {
        $provider = $this->primeAgedProvider('version');

        $fetchAttempted = false;
        self::assertTrue(stream_wrapper_unregister('https'));
        try {
            $provider->getVersion();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to fetch TRaSH-Guides data', $e->getMessage());
            $fetchAttempted = true;
        } finally {
            self::assertTrue(stream_wrapper_restore('https'));
        }

        self::assertTrue(
            $fetchAttempted,
            'TTL expiry must dislodge the instance memo and re-run the fetch (LW-053);'
                . ' the pinned memo was returned instead.'
        );

        $this->assertMemoReleasedAndStaticsCleared($provider, 'version');
    }

    /**
     * Fresh-clock counterpart pin: inside the TTL window the memo still
     * short-circuits (no fetch is attempted — an unregistering harness would
     * throw if it did, and the returned generation proves the hit).
     */
    public function testMemoStillServesInsideTtlWindow(): void
    {
        $provider   = new TrashGuidesProvider();
        $reflection = new \ReflectionClass($provider);

        $memo = $reflection->getProperty('qualityProfiles');
        $memo->setAccessible(true);
        $memo->setValue($provider, ['payload' => 'generation-1']);

        $static = $reflection->getProperty('qualityProfilesCache');
        $static->setAccessible(true);
        $static->setValue(null, ['payload' => 'generation-1']);

        $ts = $reflection->getProperty('cacheTimestamp');
        $ts->setAccessible(true);
        $ts->setValue(null, (int) (hrtime(true) / 1_000_000_000));

        $this->assertSame(['payload' => 'generation-1'], $provider->getQualityProfiles());

        $provider->clearCache();
    }

    /**
     * Primes one full completed-fetch generation (statics + instance memo)
     * aged one second PAST the 24 h TTL for one of the three ladders.
     *
     * @param 'qualityProfiles'|'customFormats'|'version' $ladder
     */
    private function primeAgedProvider(string $ladder): TrashGuidesProvider
    {
        $provider   = new TrashGuidesProvider();
        $reflection = new \ReflectionClass($provider);

        /** @var array<string|int>|string $generation */
        $generation = ['payload' => 'generation-1-' . $ladder];
        if ($ladder === 'version') {
            $generation = 'generation-1-version';
        }

        $names = [
            'qualityProfiles' => ['qualityProfilesCache', 'qualityProfiles'],
            'customFormats' => ['customFormatsCache', 'customFormats'],
            'version' => ['versionCache', 'version'],
        ][$ladder];

        $static = $reflection->getProperty($names[0]);
        $static->setAccessible(true);
        $static->setValue(null, $generation);

        $memo = $reflection->getProperty($names[1]);
        $memo->setAccessible(true);
        $memo->setValue($provider, $generation);

        $ts = $reflection->getProperty('cacheTimestamp');
        $ts->setAccessible(true);
        $ts->setValue(null, (int) (hrtime(true) / 1_000_000_000) - (86400 + 1));

        return $provider;
    }

    /**
     * After the expiry-driven refetch (which fails offline by design) the
     * ladder must leave no residue: receiver memo released, process-wide
     * statics flushed by the TTL reset.
     *
     * @param 'qualityProfiles'|'customFormats'|'version' $ladder
     */
    private function assertMemoReleasedAndStaticsCleared(TrashGuidesProvider $provider, string $ladder): void
    {
        $reflection = new \ReflectionClass($provider);

        $names = [
            'qualityProfiles' => ['qualityProfilesCache', 'qualityProfiles'],
            'customFormats' => ['customFormatsCache', 'customFormats'],
            'version' => ['versionCache', 'version'],
        ][$ladder];

        $static = $reflection->getProperty($names[0]);
        $static->setAccessible(true);
        $this->assertNull($static->getValue(), 'Expired generation must be flushed from the process-wide statics.');

        $memo = $reflection->getProperty($names[1]);
        $memo->setAccessible(true);
        $this->assertNull($memo->getValue($provider), 'Expired generation must be released from the instance memo.');

        $ts = $reflection->getProperty('cacheTimestamp');
        $ts->setAccessible(true);
        $this->assertNull($ts->getValue(), 'The TTL reset must clear the shared timestamp too.');
    }
}
