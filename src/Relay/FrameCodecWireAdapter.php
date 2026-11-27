<?php

/**
 * Relay Frame Codec Wire Adapter.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Relay;

use BadMethodCallException;

/**
 * Canonical RelayWireCodecInterface instance over the static FrameCodec.
 *
 * FRAME lane (phlix-shared-050): the package long carried two parallel
 * encode abstractions — the canonical static {@see FrameCodec} and the
 * frozen instance shape {@see RelayWireCodecInterface} the server's framer
 * adopted — with no shared instance implementation, so each host hand-rolled
 * the encoder side of the interface. This adapter closes that gap: one
 * canonical instance whose three encode legs are pure, byte-for-byte
 * delegation to the statics. Every law that lives on {@see FrameCodec::encode}
 * (the uint32 channel-id range guard, the 65535-byte payload cap, the
 * settled "channel id" terminology) is honored by construction here, because
 * there is no second encoder body to drift.
 *
 * DECODE IS DELIBERATELY NOT PROVIDED. The interface's decode() arm cannot
 * have a canonical shared implementation: the server's skip-oracle (null on
 * unknown type, buffer head skippable) and the hub's throw-on-unknown
 * semantics are intentional role-specific asymmetries — the design law
 * stated in the {@see FrameCodec} class doc ("Per-repo decoders stay
 * per-repo"). An adapter that silently picked one role's semantics would
 * make a wire-law lie possible, so decode() fails loud instead. Hosts keep
 * their own decoders; the next adoption wave points each host's ENCODE-side
 * seam at this class only.
 *
 * Stateless: the class holds no properties, any instance is interchangeable,
 * and it is final to keep that guarantee uncircumventable.
 *
 * @package Phlix\Shared\Relay
 *
 * @since S-shared-43 (phlix-shared-050 — canonical instance shape over the
 *        static encoder; ships in the next coordinated release)
 */
final class FrameCodecWireAdapter implements RelayWireCodecInterface
{
    /**
     * Encode a binary frame for transmission.
     *
     * Byte-for-byte delegation to {@see FrameCodec::encode()}: the uint32
     * channel-id guard and the 65535-byte payload cap there are the single
     * source of the rejection law (InvalidArgumentException).
     *
     * @param RelayFrameType $type    Frame type.
     * @param int            $seq     Channel id (uint32, 0..0xFFFFFFFF);
     *                                tunnel-scoped frames use 0. `seq` is the
     *                                historical field name — see
     *                                {@see RelayFrame}.
     * @param string         $payload Raw byte payload (may be empty).
     *
     * @return string Binary-encoded frame.
     *
     * @throws \InvalidArgumentException If $seq is outside 0..0xFFFFFFFF, or
     *                                   if the payload exceeds 65535 bytes.
     */
    public function encode(RelayFrameType $type, int $seq, string $payload): string
    {
        return FrameCodec::encode($type, $seq, $payload);
    }

    /**
     * Encode a HELLO handshake message (JSON text).
     *
     * Delegates to {@see FrameCodec::encodeHello()}; the HELLO JSON shape is
     * pinned by the cross-repo wire corpus there.
     *
     * @param string $enrollmentJwt JWT from stored enrollment.
     * @param string $serverId      Server UUID.
     *
     * @return string JSON text frame (not binary-encoded).
     */
    public function encodeHello(string $enrollmentJwt, string $serverId): string
    {
        return FrameCodec::encodeHello($enrollmentJwt, $serverId);
    }

    /**
     * Encode a HELLO_ACK handshake response (JSON text).
     *
     * Delegates to {@see FrameCodec::encodeHelloAck()} (two-field wire truth,
     * relay_session_id AND tunnel_id — see the rationale carried in that
     * method's docblock).
     *
     * @param string $relaySessionId Relay session UUID assigned by hub.
     * @param string $tunnelId       Tunnel UUID assigned by hub.
     *
     * @return string JSON text frame (not binary-encoded).
     */
    public function encodeHelloAck(string $relaySessionId, string $tunnelId): string
    {
        return FrameCodec::encodeHelloAck($relaySessionId, $tunnelId);
    }

    /**
     * Decode is NOT provided here — fail loud rather than pick a role.
     *
     * Shared has no canonical decoder by design: server skip-oracle and hub
     * throw-on-unknown are role-specific wire laws (see the
     * {@see FrameCodec} class doc and each repo's decoder). Any host holding
     * this adapter must route decode to its own per-repo decoder; reaching
     * this method is always a caller error, never a data condition.
     *
     * @param string $bytes Ignored — nothing decodes on this path.
     *
     * @return never
     *
     * @throws BadMethodCallException Always, naming the per-repo decoder law.
     */
    public function decode(string $bytes): ?RelayFrame
    {
        throw new BadMethodCallException(
            'FrameCodecWireAdapter: decode() is not provided — the shared encoder is canonical, '
            . 'but decode semantics are deliberately role-specific (server skip-oracle vs hub '
            . 'throw-on-unknown). Use the per-repo decoder; see FrameCodec\'s class doc.',
        );
    }
}
