<?php

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
}
