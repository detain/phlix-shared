<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Relay\RelayFrame
 */
final class RelayFrameTest extends TestCase
{
    public function test_constructor_stores_properties(): void
    {
        $frame = new RelayFrame(RelayFrameType::DATA, 42, 'hello');

        $this->assertSame(RelayFrameType::DATA, $frame->type);
        $this->assertSame(42, $frame->seq);
        $this->assertSame('hello', $frame->payload);
    }

    public function test_empty_payload_is_allowed(): void
    {
        $frame = new RelayFrame(RelayFrameType::HEARTBEAT, 0, '');

        $this->assertSame('', $frame->payload);
        $this->assertSame(0, $frame->seq);
    }

    public function test_channel_id_aliases_seq_for_client_scoped_frames(): void
    {
        // For client-scoped frames the leading field IS the channel id.
        $data = new RelayFrame(RelayFrameType::DATA, 7, 'payload');
        $this->assertSame(7, $data->channelId());
        $this->assertSame($data->seq, $data->channelId());

        $connect = new RelayFrame(RelayFrameType::CLIENT_CONNECT, 3, '{"client_id":"c"}');
        $this->assertSame(3, $connect->channelId());

        $disconnect = new RelayFrame(RelayFrameType::CLIENT_DISCONNECT, 3, '{"client_id":"c"}');
        $this->assertSame(3, $disconnect->channelId());
    }

    public function test_channel_id_is_zero_for_tunnel_scoped_frames(): void
    {
        $this->assertSame(0, (new RelayFrame(RelayFrameType::HEARTBEAT, 0, ''))->channelId());
        $this->assertSame(0, (new RelayFrame(RelayFrameType::DISCONNECTED, 0, '{}'))->channelId());
        $this->assertSame(0, (new RelayFrame(RelayFrameType::ERROR, 0, '{}'))->channelId());
    }

    /**
     * @return array<array{RelayFrameType::*, bool}>
     */
    public static function provide_type_check_methods(): array
    {
        return [
            [RelayFrameType::DATA, true],
            [RelayFrameType::HEARTBEAT, false],
            [RelayFrameType::CLIENT_CONNECT, false],
            [RelayFrameType::CLIENT_DISCONNECT, false],
            [RelayFrameType::DISCONNECTED, false],
            [RelayFrameType::ERROR, false],
            [RelayFrameType::HELLO, false],
            [RelayFrameType::HELLO_ACK, false],
        ];
    }

    /**
     * @dataProvider provide_type_check_methods
     */
    public function test_is_data(RelayFrameType $type, bool $expected): void
    {
        $frame = new RelayFrame($type, 0, '');
        $this->assertSame($expected, $frame->isData());
    }

    /**
     * @return array<array{RelayFrameType::*, bool}>
     */
    public static function provide_heartbeat_cases(): array
    {
        return [
            [RelayFrameType::HEARTBEAT, true],
            [RelayFrameType::DATA, false],
            [RelayFrameType::CLIENT_CONNECT, false],
        ];
    }

    /**
     * @dataProvider provide_heartbeat_cases
     */
    public function test_is_heartbeat(RelayFrameType $type, bool $expected): void
    {
        $frame = new RelayFrame($type, 0, '');
        $this->assertSame($expected, $frame->isHeartbeat());
    }

    /**
     * @return array<array{RelayFrameType::*, bool}>
     */
    public static function provide_client_connect_cases(): array
    {
        return [
            [RelayFrameType::CLIENT_CONNECT, true],
            [RelayFrameType::CLIENT_DISCONNECT, false],
            [RelayFrameType::DATA, false],
        ];
    }

    /**
     * @dataProvider provide_client_connect_cases
     */
    public function test_is_client_connect(RelayFrameType $type, bool $expected): void
    {
        $frame = new RelayFrame($type, 0, '');
        $this->assertSame($expected, $frame->isClientConnect());
    }

    /**
     * @return array<array{RelayFrameType::*, bool}>
     */
    public static function provide_client_disconnect_cases(): array
    {
        return [
            [RelayFrameType::CLIENT_DISCONNECT, true],
            [RelayFrameType::CLIENT_CONNECT, false],
            [RelayFrameType::DATA, false],
        ];
    }

    /**
     * @dataProvider provide_client_disconnect_cases
     */
    public function test_is_client_disconnect(RelayFrameType $type, bool $expected): void
    {
        $frame = new RelayFrame($type, 0, '');
        $this->assertSame($expected, $frame->isClientDisconnect());
    }

    /**
     * @return array<array{RelayFrameType::*, bool}>
     */
    public static function provide_disconnected_cases(): array
    {
        return [
            [RelayFrameType::DISCONNECTED, true],
            [RelayFrameType::DATA, false],
            [RelayFrameType::ERROR, false],
        ];
    }

    /**
     * @dataProvider provide_disconnected_cases
     */
    public function test_is_disconnected(RelayFrameType $type, bool $expected): void
    {
        $frame = new RelayFrame($type, 0, '');
        $this->assertSame($expected, $frame->isDisconnected());
    }

    /**
     * @return array<array{RelayFrameType::*, bool}>
     */
    public static function provide_error_cases(): array
    {
        return [
            [RelayFrameType::ERROR, true],
            [RelayFrameType::DATA, false],
            [RelayFrameType::DISCONNECTED, false],
        ];
    }

    /**
     * @dataProvider provide_error_cases
     */
    public function test_is_error(RelayFrameType $type, bool $expected): void
    {
        $frame = new RelayFrame($type, 0, '');
        $this->assertSame($expected, $frame->isError());
    }

    public function test_to_string_returns_debug_representation(): void
    {
        $frame = new RelayFrame(RelayFrameType::DATA, 123, 'hello');
        $str = (string) $frame;

        $this->assertStringContainsString('RelayFrame', $str);
        $this->assertStringContainsString('DATA', $str);
        $this->assertStringContainsString('seq=123', $str);
        $this->assertStringContainsString('payload_len=5', $str);
    }

    public function test_to_string_with_empty_payload(): void
    {
        $frame = new RelayFrame(RelayFrameType::HEARTBEAT, 0, '');
        $str = (string) $frame;

        $this->assertStringContainsString('HEARTBEAT', $str);
        $this->assertStringContainsString('payload_len=0', $str);
    }
}
