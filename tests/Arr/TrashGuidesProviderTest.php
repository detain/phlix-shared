<?php

/**
 * Trash Guides Provider Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Arr;

use Phlix\Shared\Arr\TrashGuidesProvider;
use Phlix\Shared\Tests\Support\Arr\TrashGuidesTlsMaterial;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
        $logger = $this->createStub(LoggerInterface::class);
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

    #[DataProvider('provideValidJsonStrings')]
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

    #[DataProvider('provideUrlsForVersionDerivation')]
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

    /**
     * LW-061: an included config file that does not return an array must fail
     * loud at load time — naming the path and the actual type — instead of
     * handing a phantom shape onward (a bare `return;` used to flow out of
     * loadConfig as null through the @var assertion and die later, far from
     * the cause).
     */
    public function testRequireConfigArrayAcceptsArraysAndRejectsOtherReturns(): void
    {
        $provider = new TrashGuidesProvider();
        $reflection = new \ReflectionClass($provider);
        if (!$reflection->hasMethod('requireConfigArray')) {
            $this->fail('LW-061: TrashGuidesProvider::requireConfigArray() is missing.');
        }
        $method = $reflection->getMethod('requireConfigArray');
        $method->setAccessible(true);

        $ok = ['enabled' => true, 'custom_formats_url' => 'https://example.invalid/x.json'];
        $this->assertSame($ok, $method->invoke($provider, $ok, '/tmp/trash_guides.php'));

        try {
            $method->invoke($provider, 'not-an-array', '/tmp/trash_guides.php');
            $this->fail('A scalar include return must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('/tmp/trash_guides.php', $e->getMessage());
            $this->assertStringContainsString('must return an array', $e->getMessage());
            $this->assertStringContainsString('string', $e->getMessage());
        }

        try {
            $method->invoke($provider, null, '/tmp/ghost.php');
            $this->fail('A null (bare `return;`) include return must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('/tmp/ghost.php', $e->getMessage());
            $this->assertStringContainsString('must return an array', $e->getMessage());
            $this->assertStringContainsString('NULL', $e->getMessage());
        }
    }

    /**
     * LW-061: redirect targets are resolved here (pure, no I/O) and every
     * form the spec allows must land on an absolute URL — so the caller's
     * per-hop https re-validation cannot be evaded by a relative Location,
     * a protocol-relative hop, or a header stack whose LAST response is
     * final. Downgrade targets (http://) ARE returned by this helper; the
     * refusal is fetchUrl's https gate, and the hop-by-hop drive-through is
     * pinned end-to-end by testFetchUrlRefusesRedirectDowngradeBeforeDialingIt
     * (real loopback TLS walk, LW-061 minor cure — until that test existed,
     * the per-hop refusal was intent, not exercised fact).
     */
    public function testRedirectTargetFromHeadersResolvesEveryLocationForm(): void
    {
        $provider = new TrashGuidesProvider();
        $reflection = new \ReflectionClass($provider);
        if (!$reflection->hasMethod('redirectTargetFromHeaders')) {
            $this->fail('LW-061: TrashGuidesProvider::redirectTargetFromHeaders() is missing.');
        }
        $method = $reflection->getMethod('redirectTargetFromHeaders');
        $method->setAccessible(true);

        $base = 'https://raw.example.invalid/docs/a.json';

        $this->assertSame(
            'https://cdn.example.invalid/b.json',
            $method->invoke($provider, ['HTTP/1.1 301 Moved Permanently', 'Location: https://cdn.example.invalid/b.json#frag'], $base),
            'Absolute Location wins; the fragment is stripped.'
        );
        $this->assertSame(
            'https://cdn.example.invalid/b.json',
            $method->invoke($provider, ['HTTP/1.1 302 Found', 'Location: //cdn.example.invalid/b.json'], $base),
            'Protocol-relative follows the request scheme.'
        );
        $this->assertSame(
            'https://raw.example.invalid/b.json',
            $method->invoke($provider, ['HTTP/2 307 Temporary Redirect', 'Location: /b.json'], $base),
            'Root-relative anchors on the authority.'
        );
        $this->assertSame(
            'https://raw.example.invalid/docs/b.json',
            $method->invoke($provider, ['HTTP/1.1 308 Permanent Redirect', 'Location: b.json'], $base),
            'Path-relative resolves against the base directory.'
        );
        $this->assertSame(
            'http://evil.example.invalid/b.json',
            $method->invoke($provider, ['HTTP/1.1 301 Moved Permanently', 'Location: http://evil.example.invalid/b.json'], $base),
            'A scheme-downgrade target is returned for fetchUrl to refuse.'
        );

        $this->assertNull(
            $method->invoke($provider, ['HTTP/1.1 200 OK', 'Content-Type: application/json'], $base),
            'A final 2xx is not a redirect.'
        );
        $this->assertNull(
            $method->invoke($provider, ['HTTP/1.1 304 Not Modified'], $base),
            '304 carries no usable target.'
        );
        $this->assertNull(
            $method->invoke($provider, ['HTTP/1.1 301 Moved Permanently'], $base),
            'A 3xx without Location ends the walk.'
        );
        $this->assertNull($method->invoke($provider, [], $base), 'No headers at all is final.');
        $this->assertNull(
            $method->invoke($provider, ['HTTP/1.1 404 Not Found', 'Location: /decoy.json'], $base),
            'A 4xx is never followed, Location or not.'
        );

        $this->assertSame(
            'https://final.example.invalid/z.json',
            $method->invoke($provider, [
                'HTTP/1.1 301 Moved Permanently',
                'Location: /mid.json',
                'HTTP/1.1 302 Found',
                'Location: https://final.example.invalid/z.json',
            ], $base),
            'Header stacks: the LAST response decides.'
        );
        $this->assertNull(
            $method->invoke($provider, [
                'HTTP/1.1 301 Moved Permanently',
                'Location: /mid.json',
                'HTTP/1.1 404 Not Found',
            ], $base),
            'An earlier Location must not survive a later final response.'
        );
    }

    /**
     * LW-061: the manual hop budget matches the stream setting it replaces
     * (max_redirects 5) so fetch breadth is behavior-identical.
     */
    public function testRedirectHopCapMatchesTheReplacedStreamSetting(): void
    {
        $reflection = new \ReflectionClass(TrashGuidesProvider::class);
        if (!$reflection->hasConstant('MAX_REDIRECT_HOPS')) {
            $this->fail('LW-061: TrashGuidesProvider::MAX_REDIRECT_HOPS is missing.');
        }
        $this->assertSame(5, $reflection->getConstant('MAX_REDIRECT_HOPS'));
    }

    /**
     * LW-061: the wrapper failure fact must be CHAINED into the thrown
     * RuntimeException (previous set), not @-suppressed away. The public
     * message stays the stable 'Failed to fetch TRaSH-Guides data from:'
     * form (M-SHARED-22 leak discipline preserved). The unregistered-https
     * wrapper is the repo's offline deterministic harness for the fetch leg.
     */
    public function testFetchUrlFailureChainsTheCapturedWrapperError(): void
    {
        $provider = new TrashGuidesProvider();
        $reflection = new \ReflectionClass($provider);
        $fetchUrl = $reflection->getMethod('fetchUrl');
        $fetchUrl->setAccessible(true);

        self::assertTrue(stream_wrapper_unregister('https'));
        try {
            $fetchUrl->invoke($provider, 'https://raw.example.invalid/x.json');
            $this->fail('The fetch must fail closed on the missing wrapper.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to fetch TRaSH-Guides data', $e->getMessage());
            $this->assertInstanceOf(
                \ErrorException::class,
                $e->getPrevious(),
                'LW-061: the captured wrapper warning must ride as the exception previous.'
            );
        } finally {
            self::assertTrue(stream_wrapper_restore('https'));
        }
    }

    /**
     * LW-061 minor cure (review w04L3): the per-hop https re-check must fire
     * at an ACTUAL redirect hop, before the downgraded target is dialed.
     * The pure-helper test only proves redirectTargetFromHeaders RETURNS the
     * downgrade; the three gate tests dial the initial URL only, so bypassing
     * the re-check (`$current = $target;`) used to survive the suite.
     *
     * Harness: a scripted loopback TLS server answers hop 1 with a 301 whose
     * Location leaves the https policy; fetchUrl must throw the hop-scoped
     * refusal and the server's connection log must show the initial dial and
     * nothing else. The http:// target points at port 1 (closed, privileged,
     * never dialed by correct code) so a mutant's illegal dial fails fast
     * with a DIFFERENT message instead of reaching any network.
     */
    public function testFetchUrlRefusesRedirectDowngradeBeforeDialingIt(): void
    {
        /** @var array<string, array{0: string, 1: string}> $downgrades */
        $downgrades = [
            'http-scheme downgrade' => ['http://127.0.0.1:1/evil.json', '"http"'],
            'file-scheme foreign hop' => ['file:///etc/passwd', '"file"'],
        ];

        foreach ($downgrades as $label => $leg) {
            [$target, $schemeText] = $leg;
            $outcome = $this->runFetchThroughRedirectServer(
                '/a.json',
                ['/a.json' => ['status' => 301, 'location' => $target]],
                4
            );

            $exception = $outcome['exception'] ?? null;
            $this->assertInstanceOf(
                \RuntimeException::class,
                $exception,
                "[$label] fetchUrl must refuse the downgraded hop; returned="
                    . var_export($outcome['returned'] ?? null, true)
            );
            $this->assertSame(
                'Failed to fetch TRaSH-Guides data from: ' . $outcome['url']
                    . ' (redirect hop 1: TRaSH-Guides fetch refused:'
                    . ' only https:// URLs are allowed (got scheme ' . $schemeText . '))',
                $exception->getMessage(),
                "[$label] the refusal must name the hop and the refused scheme"
            );
            $previous = $exception->getPrevious();
            $this->assertInstanceOf(
                \RuntimeException::class,
                $previous,
                "[$label] the hop refusal chains the gate's own exception"
            );
            $this->assertSame(
                'TRaSH-Guides fetch refused: only https:// URLs are allowed (got scheme ' . $schemeText . ')',
                $previous->getMessage()
            );
            $this->assertSame(
                ['/a.json 301'],
                $outcome['served'],
                "[$label] the refused target must never be dialed"
            );
        }
    }

    /**
     * LW-061 minor cure (review w04L3): the hop budget must THROW at the
     * exhausted hop — replacing the in-loop check with `if (false)` used to
     * survive the suite, because no test drove fetchUrl six hops deep.
     *
     * Seven scripted hops h0→h1→…→h6 (h6 final 200): correct code dials
     * h0..h5, trips `exceeded 5 redirect hops` before h6, chains no previous
     * (the budget throw is the origin), and the served log shows exactly the
     * six dials. A mutant with the check disabled dials h6 and RETURNS the
     * body — caught red by the exception assertion itself.
     */
    public function testFetchUrlTripsTheHopBudgetBeforeDialingTheSixthRedirect(): void
    {
        /** @var array<string, array{status: int, location?: string, body?: string}> $routes */
        $routes = [];
        for ($i = 0; $i <= 5; $i++) {
            $routes['/h' . $i . '.json'] = [
                'status' => 301,
                'location' => 'https://127.0.0.1:{port}/h' . ($i + 1) . '.json',
            ];
        }
        $routes['/h6.json'] = ['status' => 200, 'body' => '{"final":true}'];

        $outcome = $this->runFetchThroughRedirectServer('/h0.json', $routes, 8);

        $exception = $outcome['exception'] ?? null;
        $this->assertInstanceOf(
            \RuntimeException::class,
            $exception,
            'the sixth redirect must trip MAX_REDIRECT_HOPS; returned='
                . var_export($outcome['returned'] ?? null, true)
        );
        $this->assertSame(
            'Failed to fetch TRaSH-Guides data from: ' . $outcome['url'] . ' (exceeded 5 redirect hops)',
            $exception->getMessage()
        );
        $this->assertNull($exception->getPrevious(), 'the budget throw is the origin — nothing to chain');
        $this->assertSame(
            ['/h0.json 301', '/h1.json 301', '/h2.json 301', '/h3.json 301', '/h4.json 301', '/h5.json 301'],
            $outcome['served'],
            'exactly six dials: the seventh target must never be dialed'
        );
    }

    /**
     * SHR-2: the response byte ceiling is a pinned constant (32 MiB) and the
     * production default of fetchUrl's cap parameter.
     */
    public function testResponseByteCapIsPinnedAndIsTheFetchDefault(): void
    {
        $reflection = new \ReflectionClass(TrashGuidesProvider::class);
        if (!$reflection->hasConstant('MAX_RESPONSE_BYTES')) {
            $this->fail('SHR-2: TrashGuidesProvider::MAX_RESPONSE_BYTES is missing.');
        }
        $this->assertSame(33_554_432, $reflection->getConstant('MAX_RESPONSE_BYTES'));

        foreach (['fetchUrl', 'readUrlBody'] as $method) {
            $parameter = null;
            foreach ($reflection->getMethod($method)->getParameters() as $candidate) {
                if ($candidate->getName() === 'maxBytes') {
                    $parameter = $candidate;
                }
            }
            $this->assertNotNull($parameter, "SHR-2: $method must take the maxBytes cap.");
            $this->assertTrue($parameter->isDefaultValueAvailable());
            $this->assertSame(33_554_432, $parameter->getDefaultValue(), "$method cap default");
        }
    }

    /**
     * SHR-2: the fetch is length-bounded, driven through the real stream read
     * on the loopback TLS harness with a small cap. A body of exactly the cap
     * is returned whole; one byte more is refused with the stable public
     * prefix plus the cap — never returned, never decoded. A mutant that
     * drops the oversize check returns the 17-byte body and is caught red by
     * the exception assertion. Dropping only the read bound is NOT visible
     * here (the post-read strlen check throws the same message), so the
     * bounded read itself is pinned by the source assertion at the end.
     */
    public function testFetchUrlEnforcesTheResponseByteCap(): void
    {
        $atCap = str_repeat('a', 16);
        $outcome = $this->runFetchThroughRedirectServer(
            '/fits.json',
            ['/fits.json' => ['status' => 200, 'body' => $atCap]],
            2,
            16
        );
        $this->assertArrayNotHasKey('exception', $outcome, 'a body of exactly the cap must pass');
        $this->assertSame($atCap, $outcome['returned'] ?? null);
        $this->assertSame(['/fits.json 200'], $outcome['served']);

        $outcome = $this->runFetchThroughRedirectServer(
            '/big.json',
            ['/big.json' => ['status' => 200, 'body' => $atCap . 'b']],
            2,
            16
        );
        $exception = $outcome['exception'] ?? null;
        $this->assertInstanceOf(
            \RuntimeException::class,
            $exception,
            'an over-cap body must be refused; returned=' . var_export($outcome['returned'] ?? null, true)
        );
        $this->assertSame(
            'Failed to fetch TRaSH-Guides data from: ' . $outcome['url'] . ' (response exceeds the 16-byte cap)',
            $exception->getMessage()
        );
        $this->assertSame(['/big.json 200'], $outcome['served']);

        $method = new \ReflectionMethod(TrashGuidesProvider::class, 'readUrlBody');
        $file = $method->getFileName();
        $this->assertIsString($file);
        $lines = file($file);
        $this->assertIsArray($lines);
        $body = implode('', array_slice(
            $lines,
            (int) $method->getStartLine() - 1,
            (int) $method->getEndLine() - (int) $method->getStartLine() + 1
        ));
        $this->assertStringContainsString(
            '@file_get_contents($url, false, $context, 0, $maxBytes + 1)',
            $body,
            'the read itself must stay bounded at cap + 1 bytes, suppressed via the load-bearing @'
        );
    }

    /**
     * SHR-2: the upstream `version` field is gated before it can reach the
     * static cache. A non-empty string is kept verbatim; an absent or null
     * field derives from the URL (prior fallback, unchanged); a present
     * non-string or empty value fails loud naming the actual type instead of
     * riding an unverified `@var string` into `?string $versionCache`.
     */
    public function testResolveVersionGatesTheUpstreamField(): void
    {
        $provider = new TrashGuidesProvider();
        $reflection = new \ReflectionClass($provider);
        if (!$reflection->hasMethod('resolveVersion')) {
            $this->fail('SHR-2: TrashGuidesProvider::resolveVersion() is missing.');
        }
        $resolve = $reflection->getMethod('resolveVersion');
        $resolve->setAccessible(true);

        $shaUrl = 'https://raw.githubusercontent.com/TRaSH-Guides/Guides/abc1234def/docs/json/x.json';
        $branchUrl = 'https://raw.githubusercontent.com/TRaSH-Guides/Guides/main/docs/json/x.json';

        $this->assertSame('v2024.1', $resolve->invoke($provider, ['version' => 'v2024.1'], $branchUrl));
        $this->assertSame('abc1234def', $resolve->invoke($provider, [], $shaUrl));
        $this->assertSame('abc1234def', $resolve->invoke($provider, ['version' => null], $shaUrl));
        /** @var string $derived */
        $derived = $resolve->invoke($provider, [], $branchUrl);
        $this->assertMatchesRegularExpression('/^unknown-\d+$/', $derived);

        $rejects = [
            'int' => [42, 'int'],
            'float' => [1.5, 'float'],
            'bool' => [true, 'bool'],
            'array' => [['x'], 'array'],
            'empty string' => ['', 'empty string'],
        ];
        foreach ($rejects as $label => [$value, $typeText]) {
            try {
                $resolve->invoke($provider, ['version' => $value], $shaUrl);
                $this->fail("[$label] a malformed version field must throw, not be cached or derived around.");
            } catch (\RuntimeException $e) {
                $this->assertSame(
                    'TRaSH-Guides custom formats "version" field must be a non-empty string, got '
                        . $typeText . '.',
                    $e->getMessage(),
                    "[$label]"
                );
            }
        }
    }

    /**
     * SHR-2 wiring pin: getVersion() routes the decoded payload through the
     * gate and only then writes the static cache (no raw `$data['version']`
     * read survives in its body).
     */
    public function testGetVersionWritesTheCacheOnlyThroughTheGate(): void
    {
        $method = new \ReflectionMethod(TrashGuidesProvider::class, 'getVersion');
        $file = $method->getFileName();
        $this->assertIsString($file);
        $lines = file($file);
        $this->assertIsArray($lines);
        $body = implode('', array_slice(
            $lines,
            (int) $method->getStartLine() - 1,
            (int) $method->getEndLine() - (int) $method->getStartLine() + 1
        ));

        $gate = strpos($body, '$this->resolveVersion(');
        $write = strpos($body, 'self::$versionCache = $version;');
        $this->assertNotFalse($gate, 'getVersion must call resolveVersion');
        $this->assertNotFalse($write, 'getVersion must still populate the static cache');
        $this->assertLessThan($write, $gate, 'the gate must run before the cache write');
        $this->assertStringNotContainsString("\$data['version']", $body);
    }

    /**
     * Hermetic loopback harness for the two LW-061 drive-through tests.
     *
     * Starts the scripted TLS child (tests/Support/Arr/
     * trash_guides_redirect_server.php) on a reserved ephemeral 127.0.0.1
     * port, points OpenSSL's default trust at the throwaway test CA via the
     * SSL_CERT_FILE env (fetchUrl builds its own stream context, so context
     * options are unreachable and ini openssl.cafile is not INI-settable —
     * both measured 2026-11-07), drives the REAL fetchUrl through it, and
     * returns the outcome plus the server's strict dial witness. The child
     * is terminated and the env restored in every exit path; no DNS, no
     * fixed port, no external network is ever touched.
     *
     * `{port}` inside scripted Locations is substituted with the real port.
     *
     * @param array<string, array{status: int, location?: string, body?: string}> $routes
     * @param int      $maxConns Connection ceiling the child will service.
     * @param int|null $maxBytes Body byte cap handed to fetchUrl (null: the
     *                           production default).
     *
     * @return array{url: string, served: list<string>, exception?: \RuntimeException, returned?: string}
     */
    private function runFetchThroughRedirectServer(
        string $initialPath,
        array $routes,
        int $maxConns,
        ?int $maxBytes = null
    ): array {
        $dir = sys_get_temp_dir() . '/tg-e2e-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700) && !is_dir($dir)) {
            $this->fail("could not create the scratch dir $dir");
        }

        $previousCertFile = getenv('SSL_CERT_FILE');
        /** @var resource|false $process */
        $process = false;
        /** @var array<int, mixed> $pipes */
        $pipes = [];

        try {
            $material = TrashGuidesTlsMaterial::generate($dir);
            $port = TrashGuidesTlsMaterial::reservePort();

            $scriptPath = $dir . '/script.json';
            $logPath = $dir . '/served.log';
            $baked = [];
            foreach ($routes as $path => $route) {
                if (isset($route['location'])) {
                    $route['location'] = str_replace('{port}', (string) $port, $route['location']);
                }
                $baked[$path] = $route;
            }
            if (
                file_put_contents($scriptPath, json_encode(['routes' => $baked], JSON_THROW_ON_ERROR)) === false
                || file_put_contents($logPath, '') === false
            ) {
                $this->fail("could not seed the scratch dir $dir");
            }

            putenv('SSL_CERT_FILE=' . $material['ca']);

            $process = proc_open(
                [
                    PHP_BINARY,
                    dirname(__DIR__) . '/Support/Arr/trash_guides_redirect_server.php',
                    (string) $port,
                    $scriptPath,
                    $logPath,
                    $material['server'],
                    (string) $maxConns,
                ],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes
            );
            if (!is_resource($process)) {
                $this->fail('could not start the loopback TLS server child');
            }

            $ready = is_resource($pipes[1] ?? null) ? fgets($pipes[1]) : false;
            if ($ready !== "READY\n") {
                $stderr = is_resource($pipes[2] ?? null) ? (string) stream_get_contents($pipes[2]) : '';
                $this->fail('TLS server child never reported READY; stderr: ' . $stderr);
            }

            $url = 'https://127.0.0.1:' . $port . $initialPath;
            $provider = new TrashGuidesProvider();
            $fetchUrl = new \ReflectionMethod(TrashGuidesProvider::class, 'fetchUrl');
            $fetchUrl->setAccessible(true);

            /** @var array{url: string, served: list<string>, exception?: \RuntimeException, returned?: string} $outcome */
            $outcome = ['url' => $url, 'served' => []];
            try {
                /** @var string $body */
                $body = $maxBytes === null
                    ? $fetchUrl->invoke($provider, $url)
                    : $fetchUrl->invoke($provider, $url, $maxBytes);
                $outcome['returned'] = $body;
            } catch (\RuntimeException $exception) {
                $outcome['exception'] = $exception;
            }

            $rawLog = file_get_contents($logPath);
            $served = [];
            foreach (explode("\n", $rawLog === false ? '' : $rawLog) as $line) {
                if (preg_match('#^SERVED (\S+ (\d{3}))$#', $line, $match) === 1) {
                    $served[] = $match[1];
                }
            }
            $outcome['served'] = $served;

            return $outcome;
        } finally {
            if ($previousCertFile === false) {
                putenv('SSL_CERT_FILE');
            } else {
                putenv('SSL_CERT_FILE=' . $previousCertFile);
            }
            if (is_resource($process)) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_terminate($process);
                proc_close($process);
            }
            foreach (['script.json', 'served.log', 'ca.pem', 'server.pem'] as $artifact) {
                if (is_file($dir . '/' . $artifact)) {
                    unlink($dir . '/' . $artifact);
                }
            }
            @rmdir($dir);
        }
    }

    /**
     * Reflection call of the private fetchUrl() used by the seam tests: the
     * seam hands (url, remainingDeadlineSeconds) per hop and returns the raw
     * result; fetchUrl() owns the walk (phlix-shared-009).
     */
    private function invokeSeamFetch(TrashGuidesProvider $provider, string $url, ?int $maxBytes = null): string
    {
        $method = new \ReflectionMethod(TrashGuidesProvider::class, 'fetchUrl');
        $method->setAccessible(true);
        /** @var string $body */
        $body = $maxBytes === null
            ? $method->invoke($provider, $url)
            : $method->invoke($provider, $url, $maxBytes);

        return $body;
    }

    /**
     * S-shared-09: the injected transport IS the mechanism. The https stream
     * wrapper is deliberately UNREGISTERED for the call, so a green run
     * proves every dial rode the seam (any wrapper fallback would die on the
     * missing handler); the seam saw the resolved redirect target for hop 2
     * (the walk stays owned by this class) and each hop received only the
     * remaining deadline budget as its timeout.
     */
    public function testTransportSeamDrivesTheWholeRedirectWalkWithoutTheStreamWrapper(): void
    {
        /** @var list<array{url: string, timeout: float}> $calls */
        $calls = [];
        $transport = function (string $url, float $timeoutSeconds) use (&$calls): array {
            $calls[] = ['url' => $url, 'timeout' => $timeoutSeconds];
            if (count($calls) === 1) {
                return [
                    'body' => '',
                    'headers' => [
                        'HTTP/1.1 301 Moved Permanently',
                        'Location: https://cdn.example.invalid/guides.json',
                    ],
                ];
            }

            return ['body' => '{"payload":"seam"}', 'headers' => ['HTTP/1.1 200 OK']];
        };
        $provider = new TrashGuidesProvider(null, $transport);

        self::assertTrue(stream_wrapper_unregister('https'));
        try {
            $body = $this->invokeSeamFetch($provider, 'https://raw.example.invalid/guides.json');
        } finally {
            self::assertTrue(stream_wrapper_restore('https'));
        }

        $this->assertSame('{"payload":"seam"}', $body);
        $this->assertCount(2, $calls);
        $this->assertSame('https://raw.example.invalid/guides.json', $calls[0]['url']);
        $this->assertSame('https://cdn.example.invalid/guides.json', $calls[1]['url']);
        $this->assertGreaterThan(0.0, $calls[0]['timeout']);
        $this->assertLessThanOrEqual(30.0, $calls[0]['timeout']);
        $this->assertLessThanOrEqual($calls[0]['timeout'], $calls[1]['timeout']);
    }

    /**
     * S-shared-09: a downgraded redirect target is refused BEFORE the seam is
     * ever asked to dial it — the seam must not become a way to route the
     * https policy around itself. The refusal keeps the pre-existing wrapped
     * hop message and chains the gate's own exception.
     */
    public function testTransportSeamNeverDialsADowngradedRedirectTarget(): void
    {
        /** @var list<string> $dialed */
        $dialed = [];
        $transport = static function (string $url, float $timeoutSeconds) use (&$dialed): array {
            $dialed[] = $url;

            return [
                'body' => '',
                'headers' => [
                    'HTTP/1.1 301 Moved Permanently',
                    'Location: http://127.0.0.1:1/evil.json',
                ],
            ];
        };
        $provider = new TrashGuidesProvider(null, $transport);

        try {
            $this->invokeSeamFetch($provider, 'https://raw.example.invalid/guides.json');
            $this->fail('a downgraded hop must end the walk with a refusal');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Failed to fetch TRaSH-Guides data from: https://raw.example.invalid/guides.json'
                    . ' (redirect hop 1: TRaSH-Guides fetch refused:'
                    . ' only https:// URLs are allowed (got scheme "http"))',
                $exception->getMessage()
            );
            $previous = $exception->getPrevious();
            $this->assertInstanceOf(\RuntimeException::class, $previous);
            $this->assertSame(
                'TRaSH-Guides fetch refused: only https:// URLs are allowed (got scheme "http")',
                $previous->getMessage()
            );
        }

        $this->assertSame(['https://raw.example.invalid/guides.json'], $dialed);
    }

    /**
     * S-shared-09: a final (non-redirect) response must be 2xx. A 404 body,
     * an unanswered 304, or a 3xx without Location used to flow onward as if
     * it were the document, feeding upstream garbage into the *arr sync
     * chain; each is now refused, naming its status.
     */
    public function testFinalNon2xxResponsesAreRefusedWithTheirStatus(): void
    {
        $cases = [
            [404, '{"error":"gone"}', ['HTTP/1.1 404 Not Found']],
            [304, '', ['HTTP/1.1 304 Not Modified']],
            [301, '', ['HTTP/1.1 301 Moved Permanently']],
        ];
        foreach ($cases as [$status, $body, $headers]) {
            $transport = static function (string $url, float $timeoutSeconds) use ($body, $headers): array {
                return ['body' => $body, 'headers' => $headers];
            };
            $provider = new TrashGuidesProvider(null, $transport);

            try {
                $this->invokeSeamFetch($provider, 'https://raw.example.invalid/guides.json');
                $this->fail('final status ' . $status . ' must be refused');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Failed to fetch TRaSH-Guides data from:'
                        . ' https://raw.example.invalid/guides.json (final response status ' . $status . ')',
                    $exception->getMessage()
                );
            }
        }
    }

    /**
     * S-shared-09: ONE deadline bounds the WHOLE walk. Every hop burns part
     * of the 0.08 s budget; without a shared deadline the six-dial redirect
     * budget alone gave 6 x 30 s and each hop previously renewed a fresh
     * timeout. The walk must stop with the deadline refusal, strictly inside
     * the hop budget, and the very first hop must have been handed at most
     * the total budget as its timeout.
     */
    public function testOverallFetchDeadlineStopsTheRedirectWalk(): void
    {
        /** @var list<array{url: string, timeout: float}> $calls */
        $calls = [];
        $transport = function (string $url, float $timeoutSeconds) use (&$calls): array {
            $calls[] = ['url' => $url, 'timeout' => $timeoutSeconds];
            usleep(30000);

            return [
                'body' => '',
                'headers' => [
                    'HTTP/1.1 301 Moved Permanently',
                    'Location: https://h' . count($calls) . '.example.invalid/guides.json',
                ],
            ];
        };
        $provider = new TrashGuidesProvider(null, $transport, 0.08);

        try {
            $this->invokeSeamFetch($provider, 'https://raw.example.invalid/guides.json');
            $this->fail('an exhausted fetch deadline must stop the walk');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                '(overall 0.08-second fetch deadline elapsed before hop ',
                $exception->getMessage()
            );
        }

        $this->assertGreaterThanOrEqual(1, count($calls));
        $this->assertLessThan(6, count($calls), 'the deadline must trip before the redirect-hop budget does');
        $this->assertLessThanOrEqual(0.08, $calls[0]['timeout']);
    }

    /**
     * S-shared-09: the byte cap is enforced on seam bodies too — the seam is
     * not a way around the read ceiling.
     */
    public function testTransportSeamBodiesObeyTheByteCap(): void
    {
        $transport = static function (string $url, float $timeoutSeconds): array {
            return ['body' => str_repeat('a', 17), 'headers' => ['HTTP/1.1 200 OK']];
        };
        $provider = new TrashGuidesProvider(null, $transport);

        try {
            $this->invokeSeamFetch($provider, 'https://raw.example.invalid/guides.json', 16);
            $this->fail('an over-cap seam body must be refused');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Failed to fetch TRaSH-Guides data from: https://raw.example.invalid/guides.json'
                    . ' (response exceeds the 16-byte cap)',
                $exception->getMessage()
            );
        }
    }

    /**
     * S-shared-09: the seam is third-party code, so its promise is a CHECK,
     * not a comment — a non-array result, a missing/non-string body, or a
     * header stack that is not a list of raw strings fails loud with the
     * stable prefix naming the violation, before it could reach redirect
     * parsing or JSON-decode.
     */
    public function testTransportSeamShapeViolationsFailLoud(): void
    {
        /** @var list<array{0: mixed, 1: string}> $violations */
        $violations = [
            ['nope', 'transport seam returned string, expected an array'],
            [['headers' => ['HTTP/1.1 200 OK']], 'transport seam "body" must be a string, got null'],
            [['body' => 7, 'headers' => ['HTTP/1.1 200 OK']], 'transport seam "body" must be a string, got int'],
            [
                ['body' => 'ok', 'headers' => ['status' => 'HTTP/1.1 200 OK']],
                'transport seam "headers" must be a list of raw header strings',
            ],
            [
                ['body' => 'ok', 'headers' => ['HTTP/1.1 200 OK', 5]],
                'transport seam "headers" must be a list of raw header strings',
            ],
        ];
        foreach ($violations as [$result, $why]) {
            $transport = static function (string $url, float $timeoutSeconds) use ($result): mixed {
                return $result;
            };
            $provider = new TrashGuidesProvider(null, $transport);

            try {
                $this->invokeSeamFetch($provider, 'https://raw.example.invalid/guides.json');
                $this->fail('a malformed seam result must be refused: ' . $why);
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Failed to fetch TRaSH-Guides data from:'
                        . ' https://raw.example.invalid/guides.json (' . $why . ')',
                    $exception->getMessage()
                );
            }
        }
    }

    /**
     * S-shared-09: the enabled knob is honored end to end. The posture is
     * opt-OUT — absent or truthy keeps fetching (the observable behavior of
     * every config that ever ran this class); explicit false/0/'' mute the
     * cold-path ladders with the documented refusal, mirroring the sibling
     * Arr factory truthiness law.
     */
    public function testRequireEnabledHonorsTheOptOutTruthTable(): void
    {
        $provider = new TrashGuidesProvider();
        $method = new \ReflectionMethod(TrashGuidesProvider::class, 'requireEnabled');
        $method->setAccessible(true);

        $allowed = [[], ['enabled' => true], ['enabled' => 1], ['enabled' => 'keep-on']];
        foreach ($allowed as $config) {
            $method->invoke($provider, $config);
        }

        $blocked = [['enabled' => false], ['enabled' => 0], ['enabled' => '']];
        foreach ($blocked as $config) {
            try {
                $method->invoke($provider, $config);
                $this->fail('an explicit falsey "enabled" must refuse the cold-path fetch');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'TRaSH-Guides fetching is disabled (config "enabled" is false)',
                    $exception->getMessage()
                );
            }
        }
    }

    /**
     * S-shared-09: the shipped fallback config keeps enabled true, so a
     * consumer that never wrote the key (every existing deployment) still
     * fetches — the flip from the old phantom-knob fallback is a deliberate
     * opt-OUT posture, not an accidental opt-IN.
     */
    public function testFallbackConfigShipsFetchingEnabled(): void
    {
        $provider = new TrashGuidesProvider();
        $method = new \ReflectionMethod(TrashGuidesProvider::class, 'loadConfig');
        $method->setAccessible(true);
        /** @var array<string, mixed> $config */
        $config = $method->invoke($provider);

        $this->assertSame(true, $config['enabled']);
    }

    /**
     * S-shared-09 wiring law, pinned at the source level: every cold-path
     * ladder runs requireEnabled AFTER the config load and BEFORE the fetch
     * leg, so disabled fetching neither dials nor populates the memos.
     */
    public function testGettersGateEnabledFetchingBeforeDialling(): void
    {
        foreach (['getQualityProfiles', 'getCustomFormats', 'getVersion'] as $name) {
            $method = new \ReflectionMethod(TrashGuidesProvider::class, $name);
            $file = $method->getFileName();
            $this->assertIsString($file);
            $lines = file($file);
            $this->assertIsArray($lines);
            $body = implode('', array_slice(
                $lines,
                (int) $method->getStartLine() - 1,
                (int) $method->getEndLine() - (int) $method->getStartLine() + 1
            ));

            $gate = strpos($body, '$this->requireEnabled($config)');
            $dial = strpos($body, '$this->fetchUrl(');

            $this->assertIsInt($gate, $name . ' must consult requireEnabled()');
            $this->assertIsInt($dial, $name . ' must run the fetch ladder');
            $this->assertLessThan($dial, $gate, $name . ' must gate enabled BEFORE dialling anything');
        }
    }

    /**
     * S-shared-09: constructor shape + deadline validation. The seam and the
     * deadline override join the optional logger positionally without
     * breaking the zero-arg construction every consumer uses today; a
     * non-positive budget fails loud at construction, before any state is
     * assigned.
     */
    public function testConstructorShapeAndDeadlineValidation(): void
    {
        $constructor = new \ReflectionMethod(TrashGuidesProvider::class, '__construct');
        $parameters = $constructor->getParameters();

        $this->assertCount(3, $parameters);
        $this->assertSame(
            ['logger', 'transport', 'fetchDeadlineSeconds'],
            array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $parameters)
        );
        foreach ($parameters as $parameter) {
            $this->assertTrue($parameter->isOptional());
            $this->assertNull($parameter->getDefaultValue());
        }

        foreach ([0.0, -1.0] as $invalid) {
            try {
                new TrashGuidesProvider(null, null, $invalid);
                $this->fail('a non-positive deadline override must be rejected at construction');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame(
                    'TRaSH-Guides fetch deadline must be a positive number of seconds, got ' . $invalid . '.',
                    $exception->getMessage()
                );
            }
        }
    }

    /**
     * S-shared-09 error-isolation law, lexed: the provider must never
     * install or remove a GLOBAL error handler. Swapping the process-wide
     * handler for the fetch window stole the warning stream from the host
     * application's own hook; the wrapper warning now rides the chained
     * ErrorException instead, recovered from error_get_last().
     */
    public function testProviderNeverTouchesTheGlobalErrorHandler(): void
    {
        $method = new \ReflectionMethod(TrashGuidesProvider::class, 'readUrlBody');
        $file = $method->getFileName();
        $this->assertIsString($file);
        $source = file_get_contents($file);
        $this->assertIsString($source);

        $found = [];
        foreach (token_get_all($source) as $token) {
            if (
                is_array($token)
                && $token[0] === T_STRING
                && ($token[1] === 'set_error_handler' || $token[1] === 'restore_error_handler')
            ) {
                $found[] = $token[1];
            }
        }

        $this->assertSame([], $found, 'the provider must not swap the process error handler');
    }

    /**
     * S-shared-09 visibility law: the wrapper warning that backs the chained
     * ErrorException must ALSO still reach an application-installed error
     * hook. Under the old swapped-handler code the provider replaced the
     * host's handler for the fetch window and a recording hook saw nothing;
     * now @-suppression only hides display (@ never skips custom handlers),
     * so the app hook observes the warning while error_get_last() keeps
     * feeding the chain.
     */
    public function testSuppressedWrapperWarningStillReachesTheApplicationErrorHook(): void
    {
        /** @var list<string> $seen */
        $seen = [];
        $provider = new TrashGuidesProvider();

        self::assertTrue(stream_wrapper_unregister('https'));
        try {
            set_error_handler(static function (int $errno, string $message) use (&$seen): bool {
                $seen[] = $message;

                return true;
            });

            $this->invokeSeamFetch($provider, 'https://raw.example.invalid/guides.json');
            $this->fail('the fetch must fail with the wrapper unregistered');
        } catch (\RuntimeException $exception) {
            $this->assertStringStartsWith(
                'Failed to fetch TRaSH-Guides data from: https://raw.example.invalid/guides.json',
                $exception->getMessage()
            );
        } finally {
            restore_error_handler();
            self::assertTrue(stream_wrapper_restore('https'));
        }

        $observedByAppHook = array_filter(
            $seen,
            static fn (string $message): bool => str_contains($message, 'file_get_contents')
        );
        $this->assertNotEmpty(
            $observedByAppHook,
            'the application error hook must observe the suppressed wrapper warning'
        );
    }

    /**
     * S-shared-09 end to end: a public getter runs the whole ladder over the
     * seam — enabled gate, https gate, byte cap, 2xx law, JSON parse, and
     * memoization — with no network and no stream wrapper at all (the
     * wrapper stays unregistered so any fallback to it fails this test loudly
     * instead of dialing the internet).
     */
    public function testCustomFormatsLadderRunsEndToEndThroughTheSeam(): void
    {
        $calls = 0;
        $transport = static function (string $url, float $timeoutSeconds) use (&$calls): array {
            $calls++;

            return [
                'body' => '{"formats":[{"id":1,"name":"seam-formats"}]}',
                'headers' => ['HTTP/1.1 200 OK'],
            ];
        };
        $provider = new TrashGuidesProvider(null, $transport);

        self::assertTrue(stream_wrapper_unregister('https'));
        try {
            $formats = $provider->getCustomFormats();
            $this->assertSame([['id' => 1, 'name' => 'seam-formats']], $formats['formats']);

            // Same-instance memo: the second call must not re-dial.
            $again = $provider->getCustomFormats();
            $this->assertSame($formats, $again);
            $this->assertSame(1, $calls);
        } finally {
            self::assertTrue(stream_wrapper_restore('https'));
            $provider->clearCache();
        }
    }
}
