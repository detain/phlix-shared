<?php

/**
 * Relay Frame Codec.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Relay;

use InvalidArgumentException;

use function json_encode;
use function pack;
use function sprintf;
use function strlen;

/**
 * Canonical encoder for the multiplexed relay-tunnel wire protocol.
 *
 * FRAME lane (scan finding C-06): two byte-identical copies of this encoder
 * have lived since inception — phlix-server src/Hub/RelayMessageFramer.php
 * (encode/encodeHello/encodeHelloAck) and phlix-hub src/Relay/FrameDecoder.php
 * (+ its src/Relay/FrameEncoder.php wrapper). Each repo now funnels every
 * emitted byte through a single private static encodeFrame() call site, and
 * all three copies are pinned byte-identical by the triple wire-corpus tests:
 *
 *   - phlix-server tests/Unit/Hub/RelayMessageFramerWireCorpusTest.php
 *   - phlix-hub    tests/Unit/Relay/FrameWireCorpusTest.php
 *   - phlix-shared tests/Relay/FrameCodecTest.php        (this repo)
 *
 * Adoption state: the phlix-server encode legs have delegated to the
 * statics below since v0.56.0 (`src/Hub/RelayMessageFramer.php`). The hub
 * still funnels every emitted byte through its own single encoder call
 * site, pinned byte-identical to these statics by the corpus tests above;
 * delegating the hub's encode legs here — and with them re-stringing its
 * per-repo >65535 wording to the server phrasing this class takes by
 * design ruling — is the next adoption wave's disclosed job.
 *
 * Decode is deliberately NOT unified here: the server skip-oracle (null on
 * unknown type, buffer head skippable) and the hub throw-on-unknown semantics
 * are intentional role-specific asymmetries (scan finding C-07). Per-repo
 * decoders stay per-repo.
 *
 * Self-contained: zero Workerman types, zero I/O.
 *
 * Wire format (all integers big-endian):
 *   [4-byte sequence (uint32)][1-byte frame type][2-byte payload length (uint16)][N payload bytes]
 *
 * Maximum frame payload: 65535 bytes. HELLO/HELLO_ACK handshake messages are
 * JSON text exchanged immediately after the WS upgrade, before binary mode —
 * they are NOT binary-framed.
 *
 * @package Phlix\Shared\Relay
 *
 * @since 0.56.0
 */
final class FrameCodec
{
    /**
     * Maximum payload a wire frame may carry (uint16 length field).
     */
    public const MAX_PAYLOAD_LENGTH = 65535;

    /**
     * Pure static codec — no instances, no state.
     */
    private function __construct()
    {
    }

    /**
     * Encode a binary frame for transmission.
     *
     * @param RelayFrameType $type    Frame type.
     * @param int            $seq     32-bit unsigned sequence number.
     * @param string         $payload Raw byte payload.
     *
     * @return string Binary-encoded frame.
     *
     * @throws InvalidArgumentException If payload exceeds 65535 bytes.
     */
    public static function encode(RelayFrameType $type, int $seq, string $payload): string
    {
        $len = strlen($payload);

        if ($len > self::MAX_PAYLOAD_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Payload exceeds maximum length of 65535 bytes: %d', $len),
            );
        }

        $result  = pack('N', $seq);
        $result .= pack('C', $type->value);
        $result .= pack('n', $len);
        $result .= $payload;

        return $result;
    }

    /**
     * Encode a HELLO handshake message (JSON text).
     *
     * @param string $enrollmentJwt JWT from stored enrollment.
     * @param string $serverId      Server UUID.
     *
     * @return string JSON text frame (not binary-encoded).
     */
    public static function encodeHello(string $enrollmentJwt, string $serverId): string
    {
        $payload = [
            'type' => 'hello',
            'enrollment_jwt' => $enrollmentJwt,
            'server_id' => $serverId,
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Encode a HELLO_ACK handshake response (JSON text).
     *
     * Note: the original design sketch specified this with a single
     * $relaySessionId argument. That was a design erratum — the frozen
     * RelayWireCodecInterface, both repo implementations, and the actual wire
     * JSON all carry TWO fields (relay_session_id AND tunnel_id). This
     * signature follows the wire truth, not the sketch.
     *
     * @param string $relaySessionId Relay session UUID assigned by hub.
     * @param string $tunnelId       Tunnel UUID assigned by hub.
     *
     * @return string JSON text frame (not binary-encoded).
     */
    public static function encodeHelloAck(string $relaySessionId, string $tunnelId): string
    {
        $payload = [
            'type' => 'hello_ack',
            'relay_session_id' => $relaySessionId,
            'tunnel_id' => $tunnelId,
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
