<?php

declare(strict_types=1);

namespace Phlix\Shared\Relay;

/**
 * Interface for encoding and decoding relay wire protocol frames.
 *
 * Implementations handle both:
 * - Binary frames: [4-byte seq][1-byte type][2-byte len][payload]
 * - JSON text handshake: HELLO / HELLO_ACK exchanged as raw text
 *
 * @package Phlix\Shared\Relay
 * @since 0.5.0
 */
interface RelayWireCodecInterface
{
    /**
     * Encode a binary frame for transmission.
     *
     * Wire format (all integers big-endian):
     *   [4-byte sequence (uint32)][1-byte frame type][2-byte payload length (uint16)][N payload bytes]
     *
     * Maximum payload length is 65535 bytes.
     *
     * @param RelayFrameType $type    Frame type (must not be HELLO or HELLO_ACK — use encodeHello*).
     * @param int            $seq     32-bit unsigned sequence number.
     * @param string         $payload Raw byte payload (may be empty).
     *
     * @return string Binary-encoded frame.
     *
     * @throws \InvalidArgumentException If the payload exceeds 65535 bytes.
     *
     * @since 0.5.0
     */
    public function encode(RelayFrameType $type, int $seq, string $payload): string;

    /**
     * Encode a HELLO handshake message (JSON text, sent before binary mode).
     *
     * @param string $enrollmentJwt JWT from stored enrollment.
     * @param string $serverId     Server UUID.
     *
     * @return string JSON text frame (not binary-encoded).
     *
     * @since 0.5.0
     */
    public function encodeHello(string $enrollmentJwt, string $serverId): string;

    /**
     * Encode a HELLO_ACK handshake response (JSON text, sent before binary mode).
     *
     * @param string $relaySessionId Relay session UUID assigned by hub.
     * @param string $tunnelId        Tunnel UUID assigned by hub.
     *
     * @return string JSON text frame (not binary-encoded).
     *
     * @since 0.5.0
     */
    public function encodeHelloAck(string $relaySessionId, string $tunnelId): string;

    /**
     * Decode a binary frame from the wire.
     *
     * Returns null if the data is incomplete (less than 7 bytes for the header).
     * Caller is responsible for buffering partial data across multiple read calls.
     *
     * @param string $bytes Raw bytes from the WebSocket connection.
     *
     * @return RelayFrame|null Parsed frame, or null if data is incomplete.
     *
     * @since 0.5.0
     */
    public function decode(string $bytes): ?RelayFrame;
}
