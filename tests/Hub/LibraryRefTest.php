<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Hub;

use InvalidArgumentException;
use Phlix\Shared\Hub\LibraryRef;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Hub\LibraryRef
 */
final class LibraryRefTest extends TestCase
{
    public function test_from_payload_creates_instance(): void
    {
        $ref = LibraryRef::fromPayload(['library_id' => 'lib-1', 'library_name' => 'Movies']);

        $this->assertSame('lib-1', $ref->libraryId);
        $this->assertSame('Movies', $ref->libraryName);
    }

    public function test_to_payload_returns_correct_array(): void
    {
        $ref = new LibraryRef('lib-1', 'Movies');

        $this->assertSame(
            ['library_id' => 'lib-1', 'library_name' => 'Movies'],
            $ref->toPayload(),
        );
    }

    public function test_round_trip(): void
    {
        $payload = ['library_id' => 'lib-1', 'library_name' => 'Movies'];
        $ref = LibraryRef::fromPayload($payload);

        $this->assertSame($payload, $ref->toPayload());
    }

    /**
     * @dataProvider provideMissingFieldCases
     */
    public function test_from_payload_throws_on_missing_field(string $field): void
    {
        $payload = ['library_id' => 'lib-1', 'library_name' => 'Movies'];
        unset($payload[$field]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('LibraryRef "%s" must be a non-empty string.', $field));
        LibraryRef::fromPayload($payload);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideMissingFieldCases(): array
    {
        return [
            'missing library_id' => ['library_id'],
            'missing library_name' => ['library_name'],
        ];
    }

    /**
     * @dataProvider provideEmptyFieldCases
     */
    public function test_from_payload_throws_on_empty_field(string $field): void
    {
        $payload = ['library_id' => 'lib-1', 'library_name' => 'Movies'];
        $payload[$field] = '';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('LibraryRef "%s" must be a non-empty string.', $field));
        LibraryRef::fromPayload($payload);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideEmptyFieldCases(): array
    {
        return [
            'empty library_id' => ['library_id'],
            'empty library_name' => ['library_name'],
        ];
    }

    /**
     * @dataProvider provideWrongTypeCases
     */
    public function test_from_payload_throws_on_wrong_type(string $field, mixed $value): void
    {
        $payload = ['library_id' => 'lib-1', 'library_name' => 'Movies'];
        $payload[$field] = $value;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('LibraryRef "%s" must be a non-empty string.', $field));
        LibraryRef::fromPayload($payload);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function provideWrongTypeCases(): array
    {
        return [
            'library_id is int' => ['library_id', 123],
            'library_id is array' => ['library_id', ['arr']],
            'library_id is null' => ['library_id', null],
            'library_id is bool' => ['library_id', true],
            'library_name is int' => ['library_name', 456],
            'library_name is array' => ['library_name', ['arr']],
            'library_name is null' => ['library_name', null],
            'library_name is bool' => ['library_name', false],
        ];
    }
}
