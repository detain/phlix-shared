<?php

/**
 * Relay Frame.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Relay;

/**
 * Immutable value object representing a relay wire protocol frame.
 *
 * Used for binary frames only (DATA, HEARTBEAT, CLIENT_CONNECT, etc.).
 * The HELLO/HELLO_ACK handshake uses JSON text and is handled separately
 * by the codec's encodeHello / encodeHelloAck methods.
 *
 * ## The `seq` field carries a per-client CHANNEL ID (multiplexing)
 *
 * The relay tunnel runs over a single reliable WebSocket/TCP connection, so
 * the 4-byte `seq` field is NOT used for acknowledgements or reordering.
 * Instead it is repurposed as a per-client **channel id (uint32)** for the
 * client-scoped frame types, so multiple concurrent clients can be
 * demultiplexed over one tunnel:
 *
 *   - CLIENT_CONNECT    — `seq` = the channel id the hub assigns to this client.
 *   - CLIENT_DISCONNECT — `seq` = the channel id of the client that left.
 *   - DATA              — `seq` = the channel id the bytes belong to (both
 *                          server→hub→client and client→hub→server directions).
 *
 * Tunnel-scoped frames carry no channel and use channel id 0:
 *
 *   - HEARTBEAT, HELLO_ACK, DISCONNECTED, ERROR — `seq` = 0.
 *
 * The hub allocates channel ids (1, 2, 3, …) at CLIENT_CONNECT time and the
 * server maps each channel to a local connection. The JSON `client_id` /
 * `session_id` payload on CLIENT_CONNECT / CLIENT_DISCONNECT is retained for
 * logging/observability only — routing is keyed on the channel id.
 *
 * Use {@see RelayFrame::channelId()} when reading the field as a channel id;
 * it is exactly the value of {@see RelayFrame::$seq}.
 *
 * @package Phlix\Shared\Relay
 * @since 0.5.0
 */
final readonly class RelayFrame
{
    /**
     * @param RelayFrameType $type    Frame type.
     * @param int            $seq     32-bit unsigned value. For client-scoped
     *                                frames (CLIENT_CONNECT, CLIENT_DISCONNECT,
     *                                DATA) this is the per-client channel id;
     *                                tunnel-scoped frames use 0. See class doc.
     * @param string         $payload Raw byte payload (may be empty).
     */
    public function __construct(
        public RelayFrameType $type,
        public int $seq,
        public string $payload,
    ) {
    }

    /**
     * Returns the per-client channel id carried by this frame.
     *
     * The channel id is stored in the {@see RelayFrame::$seq} field (the tunnel
     * is reliable, so `seq` is not an ack/sequence counter — see class doc). For
     * tunnel-scoped frames (HEARTBEAT, HELLO_ACK, DISCONNECTED, ERROR) this is 0.
     *
     * @return int Channel id (uint32), or 0 for tunnel-scoped frames.
     *
     * @since 0.5.0
     */
    public function channelId(): int
    {
        return $this->seq;
    }

    /**
     * Returns true if this is a DATA frame.
     *
     * @return bool
     *
     * @since 0.5.0
     */
    public function isData(): bool
    {
        return $this->type === RelayFrameType::DATA;
    }

    /**
     * Returns true if this is a HEARTBEAT frame.
     *
     * @return bool
     *
     * @since 0.5.0
     */
    public function isHeartbeat(): bool
    {
        return $this->type === RelayFrameType::HEARTBEAT;
    }

    /**
     * Returns true if this is a CLIENT_CONNECT frame.
     *
     * @return bool
     *
     * @since 0.5.0
     */
    public function isClientConnect(): bool
    {
        return $this->type === RelayFrameType::CLIENT_CONNECT;
    }

    /**
     * Returns true if this is a CLIENT_DISCONNECT frame.
     *
     * @return bool
     *
     * @since 0.5.0
     */
    public function isClientDisconnect(): bool
    {
        return $this->type === RelayFrameType::CLIENT_DISCONNECT;
    }

    /**
     * Returns true if this is a DISCONNECTED frame.
     *
     * @return bool
     *
     * @since 0.5.0
     */
    public function isDisconnected(): bool
    {
        return $this->type === RelayFrameType::DISCONNECTED;
    }

    /**
     * Returns true if this is an ERROR frame.
     *
     * @return bool
     *
     * @since 0.5.0
     */
    public function isError(): bool
    {
        return $this->type === RelayFrameType::ERROR;
    }

    /**
     * Create a string representation for debugging.
     *
     * @return string
     *
     * @since 0.5.0
     */
    public function __toString(): string
    {
        return sprintf(
            'RelayFrame(%s, seq=%d, payload_len=%d)',
            $this->type->label(),
            $this->seq,
            strlen($this->payload),
        );
    }
}
