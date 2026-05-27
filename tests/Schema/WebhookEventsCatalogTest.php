<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Schema;

use Phlix\Shared\Schema\SchemaPaths;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Schema\SchemaPaths
 */
final class WebhookEventsCatalogTest extends TestCase
{
    private const TYPE_PATTERN = '/^[a-z]+(?:\.[a-z_]+)*$/';

    /**
     * Decoded webhook event catalog document.
     *
     * @return array<string, mixed>
     */
    private static function catalog(): array
    {
        $raw = (string) file_get_contents(SchemaPaths::webhookEvents());
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'webhook-events.json must decode to a JSON object.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The `events` list as an array of decoded objects.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function events(): array
    {
        $catalog = self::catalog();
        self::assertArrayHasKey('events', $catalog);
        self::assertIsArray($catalog['events']);

        $events = [];
        foreach ($catalog['events'] as $event) {
            self::assertIsArray($event, 'Each entry of events[] must be a JSON object.');
            /** @var array<string, mixed> $event */
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Index of the `events` list keyed by event type.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function eventsByType(): array
    {
        $byType = [];
        foreach (self::events() as $event) {
            $type = $event['type'] ?? null;
            self::assertIsString($type, 'Each event must have a string type.');
            $byType[$type] = $event;
        }

        return $byType;
    }

    /**
     * The 7 expected user-subscribable webhook event types.
     *
     * @return array<string, array{0: string}>
     */
    public static function eventTypeProvider(): array
    {
        return [
            'playback.started' => ['playback.started'],
            'playback.ended' => ['playback.ended'],
            'library.updated' => ['library.updated'],
            'download.complete' => ['download.complete'],
            'recording.started' => ['recording.started'],
            'recording.stopped' => ['recording.stopped'],
            'alert' => ['alert'],
        ];
    }

    public function test_catalog_declares_the_expected_meta_header(): void
    {
        $catalog = self::catalog();
        $this->assertSame('https://phlix.tv/schemas/webhook-events.json', $catalog['$id'] ?? null);
        $this->assertSame('Phlix webhook event catalog', $catalog['title'] ?? null);

        // It is a data document, not a JSON Schema — it must NOT advertise a
        // schema meta dialect.
        $this->assertArrayNotHasKey('$schema', $catalog);
    }

    public function test_catalog_lists_exactly_the_seven_expected_event_types(): void
    {
        $actual = array_keys(self::eventsByType());
        $expected = array_map(
            static fn (array $row): string => $row[0],
            array_values(self::eventTypeProvider())
        );

        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual, 'webhook catalog must list exactly the 7 supported event types.');
        $this->assertCount(7, $actual);
    }

    /**
     * @dataProvider eventTypeProvider
     */
    public function test_event_has_non_empty_group_label_and_description(string $type): void
    {
        $byType = self::eventsByType();
        $this->assertArrayHasKey($type, $byType);
        $event = $byType[$type];

        foreach (['group', 'label', 'description'] as $field) {
            $value = $event[$field] ?? null;
            $this->assertIsString($value, sprintf('Event "%s" must have a string %s.', $type, $field));
            $this->assertNotSame('', $value, sprintf('Event "%s" %s must be non-empty.', $type, $field));
        }
    }

    /**
     * @dataProvider eventTypeProvider
     */
    public function test_event_type_is_well_formed(string $type): void
    {
        $this->assertMatchesRegularExpression(self::TYPE_PATTERN, $type);
    }

    public function test_reserved_set_contains_internal_webhook_test(): void
    {
        $catalog = self::catalog();
        $this->assertArrayHasKey('reserved', $catalog);
        $this->assertIsArray($catalog['reserved']);

        $reservedByType = [];
        foreach ($catalog['reserved'] as $entry) {
            $this->assertIsArray($entry, 'Each reserved entry must be a JSON object.');
            $type = $entry['type'] ?? null;
            $this->assertIsString($type, 'Each reserved entry must have a string type.');
            $reservedByType[$type] = $entry;
        }

        $this->assertArrayHasKey('webhook.test', $reservedByType);
        $this->assertTrue(
            $reservedByType['webhook.test']['internal'] ?? null,
            'webhook.test must be flagged internal:true.'
        );
        $this->assertMatchesRegularExpression(self::TYPE_PATTERN, 'webhook.test');
    }

    public function test_no_event_type_collides_with_the_reserved_set(): void
    {
        $catalog = self::catalog();
        $this->assertArrayHasKey('reserved', $catalog);
        $this->assertIsArray($catalog['reserved']);

        $reservedTypes = [];
        foreach ($catalog['reserved'] as $entry) {
            $this->assertIsArray($entry);
            $type = $entry['type'] ?? null;
            $this->assertIsString($type);
            $reservedTypes[] = $type;
        }

        $eventTypes = array_keys(self::eventsByType());
        $collisions = array_intersect($eventTypes, $reservedTypes);

        $this->assertSame([], $collisions, 'No subscribable event type may also appear in the reserved set.');
    }
}
