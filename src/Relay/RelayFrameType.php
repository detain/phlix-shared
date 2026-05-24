<?php

declare(strict_types=1);

namespace Phlix\Shared\Relay;

use InvalidArgumentException;

/**
 * Frame type constants for the multiplexed WebSocket relay protocol.
 *
 * Wire format (all integers are big-endian):
 *
 *   [4-byte sequence (uint32)][1-byte frame type][2-byte payload length (uint16)][N payload bytes]
 *
 * Maximum frame payload: 65535 bytes.
 *
 * Types:
 *   HELLO            = 0x01 — S→H: JSON text sent immediately after WS upgrade
 *   HELLO_ACK        = 0x02 — H→S: JSON text response to HELLO
 *   CLIENT_CONNECT   = 0x03 — H→S: notification that a client connected to the tunnel
 *   CLIENT_DISCONNECT= 0x04 — H→S: notification that a client disconnected from the tunnel
 *   DATA             = 0x05 — S↔H↔C: raw bytes forwarded verbatim
 *   HEARTBEAT        = 0x06 — either→either: keep-alive probe/ack
 *   DISCONNECTED      = 0x07 — H→C: server tunnel closed, client should reconnect
 *   ERROR            = 0x08 — H↔any: error condition
 *
 * @package Phlix\Shared\Relay
 * @since 0.5.0
 */
enum RelayFrameType: int
{
    case HELLO = 0x01;
    case HELLO_ACK = 0x02;
    case CLIENT_CONNECT = 0x03;
    case CLIENT_DISCONNECT = 0x04;
    case DATA = 0x05;
    case HEARTBEAT = 0x06;
    case DISCONNECTED = 0x07;
    case ERROR = 0x08;

    /**
     * Returns the human-readable name of this frame type.
     *
     * @return non-empty-string
     *
     * @since 0.5.0
     */
    public function label(): string
    {
        return match ($this) {
            self::HELLO => 'HELLO',
            self::HELLO_ACK => 'HELLO_ACK',
            self::CLIENT_CONNECT => 'CLIENT_CONNECT',
            self::CLIENT_DISCONNECT => 'CLIENT_DISCONNECT',
            self::DATA => 'DATA',
            self::HEARTBEAT => 'HEARTBEAT',
            self::DISCONNECTED => 'DISCONNECTED',
            self::ERROR => 'ERROR',
        };
    }

    /**
     * Create a RelayFrameType from its integer value.
     *
     * @param int $value The byte value (0x01–0x08).
     *
     * @return self
     *
     * @throws InvalidArgumentException If the value is not a valid frame type.
     *
     * @since 0.5.0
     */
    public static function fromValue(int $value): self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new InvalidArgumentException(
            sprintf('Invalid relay frame type value: 0x%02X', $value),
        );
    }

    /**
     * Returns true if the given integer value is a valid frame type.
     *
     * @param int $value The byte value to check.
     *
     * @return bool True if valid.
     *
     * @since 0.5.0
     */
    public static function isValid(int $value): bool
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return true;
            }
        }

        return false;
    }
}
