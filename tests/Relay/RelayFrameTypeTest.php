<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use InvalidArgumentException;
use Phlix\Shared\Relay\RelayFrameType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Relay\RelayFrameType
 */
final class RelayFrameTypeTest extends TestCase
{
    /**
     * @return array<array{RelayFrameType::*, int, non-empty-string}>
     */
    public static function provideCases(): array
    {
        return [
            [RelayFrameType::HELLO, 0x01, 'HELLO'],
            [RelayFrameType::HELLO_ACK, 0x02, 'HELLO_ACK'],
            [RelayFrameType::CLIENT_CONNECT, 0x03, 'CLIENT_CONNECT'],
            [RelayFrameType::CLIENT_DISCONNECT, 0x04, 'CLIENT_DISCONNECT'],
            [RelayFrameType::DATA, 0x05, 'DATA'],
            [RelayFrameType::HEARTBEAT, 0x06, 'HEARTBEAT'],
            [RelayFrameType::DISCONNECTED, 0x07, 'DISCONNECTED'],
            [RelayFrameType::ERROR, 0x08, 'ERROR'],
        ];
    }

    /**
     * @dataProvider provideCases
     */
    public function test_enum_case_has_correct_value(RelayFrameType $case, int $expected, string $label): void
    {
        $this->assertSame($expected, $case->value);
        $this->assertSame($label, $case->label());
    }

    public function test_from_value_returns_correct_case(): void
    {
        $this->assertSame(RelayFrameType::HELLO, RelayFrameType::fromValue(0x01));
        $this->assertSame(RelayFrameType::HELLO_ACK, RelayFrameType::fromValue(0x02));
        $this->assertSame(RelayFrameType::CLIENT_CONNECT, RelayFrameType::fromValue(0x03));
        $this->assertSame(RelayFrameType::CLIENT_DISCONNECT, RelayFrameType::fromValue(0x04));
        $this->assertSame(RelayFrameType::DATA, RelayFrameType::fromValue(0x05));
        $this->assertSame(RelayFrameType::HEARTBEAT, RelayFrameType::fromValue(0x06));
        $this->assertSame(RelayFrameType::DISCONNECTED, RelayFrameType::fromValue(0x07));
        $this->assertSame(RelayFrameType::ERROR, RelayFrameType::fromValue(0x08));
    }

    public function test_from_value_throws_on_invalid_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid relay frame type value: 0x00');
        RelayFrameType::fromValue(0x00);
    }

    public function test_is_valid_returns_true_for_valid_values(): void
    {
        $this->assertTrue(RelayFrameType::isValid(0x01));
        $this->assertTrue(RelayFrameType::isValid(0x02));
        $this->assertTrue(RelayFrameType::isValid(0x03));
        $this->assertTrue(RelayFrameType::isValid(0x04));
        $this->assertTrue(RelayFrameType::isValid(0x05));
        $this->assertTrue(RelayFrameType::isValid(0x06));
        $this->assertTrue(RelayFrameType::isValid(0x07));
        $this->assertTrue(RelayFrameType::isValid(0x08));
        $this->assertTrue(RelayFrameType::isValid(0x09)); // HUB_HELLO
        $this->assertTrue(RelayFrameType::isValid(0x0A)); // HUB_HELLO_ACK
        $this->assertTrue(RelayFrameType::isValid(0x0B)); // HUB_HEARTBEAT
        $this->assertTrue(RelayFrameType::isValid(0x0C)); // LIBRARY_SHARE_UPDATE
        $this->assertTrue(RelayFrameType::isValid(0x0D)); // LIBRARY_SHARE_REVOKED
        $this->assertTrue(RelayFrameType::isValid(0x0E)); // ADMIN_DELEGATION
        $this->assertTrue(RelayFrameType::isValid(0x0F)); // HUB_DISCONNECTED
    }

    public function test_is_valid_returns_false_for_invalid_values(): void
    {
        $this->assertFalse(RelayFrameType::isValid(0x00));
        $this->assertFalse(RelayFrameType::isValid(0x10)); // first invalid after new range
        $this->assertFalse(RelayFrameType::isValid(0xFF));
        $this->assertFalse(RelayFrameType::isValid(-1));
    }

    /**
     * @return array<array{non-empty-string, RelayFrameType::*}>
     */
    public static function provideLabelCases(): array
    {
        return [
            ['HELLO', RelayFrameType::HELLO],
            ['HELLO_ACK', RelayFrameType::HELLO_ACK],
            ['CLIENT_CONNECT', RelayFrameType::CLIENT_CONNECT],
            ['CLIENT_DISCONNECT', RelayFrameType::CLIENT_DISCONNECT],
            ['DATA', RelayFrameType::DATA],
            ['HEARTBEAT', RelayFrameType::HEARTBEAT],
            ['DISCONNECTED', RelayFrameType::DISCONNECTED],
            ['ERROR', RelayFrameType::ERROR],
            ['HUB_HELLO', RelayFrameType::HUB_HELLO],
            ['HUB_HELLO_ACK', RelayFrameType::HUB_HELLO_ACK],
            ['HUB_HEARTBEAT', RelayFrameType::HUB_HEARTBEAT],
            ['LIBRARY_SHARE_UPDATE', RelayFrameType::LIBRARY_SHARE_UPDATE],
            ['LIBRARY_SHARE_REVOKED', RelayFrameType::LIBRARY_SHARE_REVOKED],
            ['ADMIN_DELEGATION', RelayFrameType::ADMIN_DELEGATION],
            ['HUB_DISCONNECTED', RelayFrameType::HUB_DISCONNECTED],
        ];
    }

    /**
     * @dataProvider provideLabelCases
     */
    public function test_label_returns_correct_string(string $expected, RelayFrameType $case): void
    {
        $this->assertSame($expected, $case->label());
    }
}
