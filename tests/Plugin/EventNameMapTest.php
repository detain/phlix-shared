<?php

/**
 * Event Name Map Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Plugin;

use Phlix\Shared\Events\Auth\UserCreated;
use Phlix\Shared\Events\Auth\UserLoggedIn;
use Phlix\Shared\Events\Auth\UserLoggedOut;
use Phlix\Shared\Events\Library\LibraryScanCompleted;
use Phlix\Shared\Events\Library\LibraryScanStarted;
use Phlix\Shared\Events\Library\MediaItemAdded;
use Phlix\Shared\Events\Library\MediaItemRemoved;
use Phlix\Shared\Events\Library\MediaItemUpdated;
use Phlix\Shared\Events\Playback\PlaybackPaused;
use Phlix\Shared\Events\Playback\PlaybackResumed;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Phlix\Shared\Plugin\EventNameMap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Plugin\EventNameMap
 */
final class EventNameMapTest extends TestCase
{
    /**
     * @return array<int, array{0: string, 1: class-string}>
     */
    public static function aliasProvider(): array
    {
        return [
            ['phlix.playback.started',       PlaybackStarted::class],
            ['phlix.playback.paused',        PlaybackPaused::class],
            ['phlix.playback.resumed',       PlaybackResumed::class],
            ['phlix.playback.stopped',       PlaybackStopped::class],
            ['phlix.library.scan.started',   LibraryScanStarted::class],
            ['phlix.library.scan.completed', LibraryScanCompleted::class],
            ['phlix.library.item.added',     MediaItemAdded::class],
            ['phlix.library.item.updated',   MediaItemUpdated::class],
            ['phlix.library.item.removed',   MediaItemRemoved::class],
            ['phlix.user.created',           UserCreated::class],
            ['phlix.user.logged_in',         UserLoggedIn::class],
            ['phlix.user.logged_out',        UserLoggedOut::class],
        ];
    }

    /**
     * @dataProvider aliasProvider
     * @param class-string $fqcn
     */
    public function test_fromAlias_returns_fqcn(string $alias, string $fqcn): void
    {
        $this->assertSame($fqcn, EventNameMap::fromAlias($alias));
    }

    /**
     * @dataProvider aliasProvider
     * @param class-string $fqcn
     */
    public function test_toAlias_returns_alias(string $alias, string $fqcn): void
    {
        $this->assertSame($alias, EventNameMap::toAlias($fqcn));
    }

    public function test_fromAlias_returns_null_for_unknown_alias(): void
    {
        $this->assertNull(EventNameMap::fromAlias('phlix.unknown'));
    }

    public function test_toAlias_returns_null_for_unknown_fqcn(): void
    {
        $this->assertNull(EventNameMap::toAlias('Acme\\NotAnEvent'));
    }

    public function test_aliases_returns_sorted_map_of_twelve_entries(): void
    {
        $aliases = EventNameMap::aliases();
        $this->assertCount(12, $aliases);
        $keys = array_keys($aliases);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys, 'aliases() must be sorted by alias.');
    }

    /**
     * LW-062: drift guard for the class-header invariant "one entry per
     * event class published by src/Events/". Walks the tree, maps each
     * concrete class file to its PSR-4 FQCN, and pins set-equality against
     * the map values. Adding an event class without a map entry (or vice
     * versa) fails CI at the adding commit instead of silently shipping a
     * manifest alias that can never dispatch.
     */
    public function test_the_alias_map_tracks_the_event_class_tree(): void
    {
        $srcRoot = dirname(__DIR__, 2) . '/src/Events';
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($srcRoot) + 1, -strlen('.php'));
            $fqcn = 'Phlix\\Shared\\Events\\' . str_replace('/', '\\', $relative);
            if ($fqcn === 'Phlix\\Shared\\Events\\AbstractEvent') {
                continue; // abstract base — not dispatchable, not in the map by design
            }
            $this->assertTrue(
                class_exists($fqcn),
                "Event file {$relative}.php must define class {$fqcn} (PSR-4 layout)."
            );
            $found[] = $fqcn;
        }

        sort($found);
        $mapped = array_values(EventNameMap::aliases());
        sort($mapped);

        $this->assertSame(
            $found,
            $mapped,
            'EventNameMap must carry exactly one alias per concrete event class under'
                . ' src/Events/ — update the table in the same commit as the class (LW-062).'
        );
    }
}
