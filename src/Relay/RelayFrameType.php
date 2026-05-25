<?php

declare(strict_types=1);

namespace Phlix\Shared\Relay;

use InvalidArgumentException;

/**
 * Frame type constants for the multiplexed WebSocket relay protocol.
 *
 * Wire format (all integers are big-endian):
 *
 *   [4-byte channel/seq (uint32)][1-byte frame type][2-byte payload length (uint16)][N payload bytes]
 *
 * Maximum frame payload: 65535 bytes.
 *
 * ## Channel multiplexing (the 4-byte field)
 *
 * The tunnel is a single reliable WS/TCP stream, so the leading 4-byte field
 * is NOT an ack/sequence counter. For client-scoped frames it carries a
 * per-client **channel id (uint32)** so multiple concurrent clients are
 * demultiplexed over one tunnel; tunnel-scoped frames use channel 0. See
 * {@see RelayFrame} for the full description.
 *
 * Types:
 *   HELLO            = 0x01 — S→H: JSON text sent immediately after WS upgrade (channel 0)
 *   HELLO_ACK        = 0x02 — H→S: JSON text response to HELLO (channel 0)
 *   CLIENT_CONNECT   = 0x03 — H→S: a client connected; channel = the client's channel id;
 *                                  payload {"client_id","session_id"} (observability only)
 *   CLIENT_DISCONNECT= 0x04 — H→S: a client disconnected; channel = that client's channel id;
 *                                  payload {"client_id"} (observability only)
 *   DATA             = 0x05 — S↔H↔C: raw bytes forwarded verbatim; channel = owning client
 *   HEARTBEAT        = 0x06 — either→either: keep-alive probe/ack (channel 0)
 *   DISCONNECTED     = 0x07 — H→C: server tunnel closed, client should reconnect (channel 0)
 *   ERROR            = 0x08 — H↔any: error condition (channel 0)
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
