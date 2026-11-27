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
 * FRAME lane: two byte-identical copies of this encoder
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
 * Instance shape: {@see FrameCodecWireAdapter} exposes these statics as the
 * canonical {@see RelayWireCodecInterface} implementation (encode legs are
 * pure delegation; its decode() fails loud — see that class for the law).
 *
 * Decode is deliberately NOT unified here: the server skip-oracle (null on
 * unknown type, buffer head skippable) and the hub throw-on-unknown semantics
 * are intentional role-specific asymmetries. Per-repo
 * decoders stay per-repo.
 *
 * Self-contained: zero Workerman types, zero I/O.
 *
 * Wire format (all integers big-endian):
 *   [4-byte channel id (uint32)][1-byte frame type][2-byte payload length (uint16)][N payload bytes]
 *
 * Terminology (settled): the 4-byte field is the per-client CHANNEL ID — the
 * relay tunnel is a single reliable connection, so it never acts as an
 * ack/ordering counter; see {@see RelayFrame} for the multiplexing contract.
 * `seq` stays the code-level property/parameter name (and the bare
 * "sequence" label in older prose is a retired alias); "channel id" is the
 * one term this package uses for the field in documentation.
 *
 * Maximum frame payload: 65535 bytes; encode() fails fast on a channel id
 * outside uint32 rather than letting pack('N') wrap it silently.
 * HELLO/HELLO_ACK handshake messages are JSON text exchanged immediately
 * after the WS upgrade, before binary mode — they are NOT binary-framed.
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
     * Largest value the 4-byte channel-id wire field can carry (uint32).
     * Mirrors the bound {@see RelayFrame} enforces on its `$seq` property —
     * one field, one law, on both entry points into the wire.
     */
    private const MAX_SEQ = 0xFFFFFFFF;

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
     * @param int            $seq     Channel id (uint32, 0..0xFFFFFFFF);
     *                                tunnel-scoped frames use 0. `seq` is the
     *                                historical field name — see class doc and
     *                                {@see RelayFrame}.
     * @param string         $payload Raw byte payload.
     *
     * @return string Binary-encoded frame.
     *
     * @throws InvalidArgumentException If $seq is outside 0..0xFFFFFFFF, or
     *                                  if the payload exceeds 65535 bytes.
     */
    public static function encode(RelayFrameType $type, int $seq, string $payload): string
    {
        if ($seq < 0 || $seq > self::MAX_SEQ) {
            throw new InvalidArgumentException(
                sprintf('FrameCodec: "seq" must be a uint32 channel id (0..0xFFFFFFFF), got %d', $seq),
            );
        }

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
