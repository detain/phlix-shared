<?php

declare(strict_types=1);

namespace Phlix\Shared\Relay;

/**
 * Immutable value object representing a relay wire protocol frame.
 *
 * Used for binary frames only (DATA, HEARTBEAT, CLIENT_CONNECT, etc.).
 * The HELLO/HELLO_ACK handshake uses JSON text and is handled separately
 * by the codec's encodeHello / encodeHelloAck methods.
 *
 * @package Phlix\Shared\Relay
 * @since 0.5.0
 */
final readonly class RelayFrame
{
    /**
     * @param RelayFrameType $type    Frame type.
     * @param int            $seq    32-bit unsigned sequence number.
     * @param string         $payload Raw byte payload (may be empty).
     */
    public function __construct(
        public RelayFrameType $type,
        public int $seq,
        public string $payload,
    ) {
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
