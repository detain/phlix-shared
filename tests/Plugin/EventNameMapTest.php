<?php

declare(strict_types=1);

namespace Phlex\Shared\Tests\Plugin;

use Phlex\Shared\Events\Auth\UserCreated;
use Phlex\Shared\Events\Auth\UserLoggedIn;
use Phlex\Shared\Events\Auth\UserLoggedOut;
use Phlex\Shared\Events\Library\LibraryScanCompleted;
use Phlex\Shared\Events\Library\LibraryScanStarted;
use Phlex\Shared\Events\Library\MediaItemAdded;
use Phlex\Shared\Events\Library\MediaItemRemoved;
use Phlex\Shared\Events\Library\MediaItemUpdated;
use Phlex\Shared\Events\Playback\PlaybackPaused;
use Phlex\Shared\Events\Playback\PlaybackResumed;
use Phlex\Shared\Events\Playback\PlaybackStarted;
use Phlex\Shared\Events\Playback\PlaybackStopped;
use Phlex\Shared\Plugin\EventNameMap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Shared\Plugin\EventNameMap
 */
final class EventNameMapTest extends TestCase
{
    /**
     * @return array<int, array{0: string, 1: class-string}>
     */
    public static function aliasProvider(): array
    {
        return [
            ['phlex.playback.started',       PlaybackStarted::class],
            ['phlex.playback.paused',        PlaybackPaused::class],
            ['phlex.playback.resumed',       PlaybackResumed::class],
            ['phlex.playback.stopped',       PlaybackStopped::class],
            ['phlex.library.scan.started',   LibraryScanStarted::class],
            ['phlex.library.scan.completed', LibraryScanCompleted::class],
            ['phlex.library.item.added',     MediaItemAdded::class],
            ['phlex.library.item.updated',   MediaItemUpdated::class],
            ['phlex.library.item.removed',   MediaItemRemoved::class],
            ['phlex.user.created',           UserCreated::class],
            ['phlex.user.logged_in',         UserLoggedIn::class],
            ['phlex.user.logged_out',        UserLoggedOut::class],
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
        $this->assertNull(EventNameMap::fromAlias('phlex.unknown'));
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
