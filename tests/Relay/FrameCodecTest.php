<?php

/**
 * Relay Frame Codec wire corpus test (FRAME lane, scan finding C-06).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use InvalidArgumentException;
use Phlix\Shared\Relay\FrameCodec;
use Phlix\Shared\Relay\RelayFrameType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function chr;
use function md5;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Byte-identity pins for the canonical relay frame ENCODE home.
 *
 * The server copy lives at phlix-server src/Hub/RelayMessageFramer.php and
 * the hub copy at phlix-hub src/Relay/FrameDecoder.php (+ FrameEncoder.php
 * wrapper). The identical corpus builder, frame/byte counts and md5 pin
 * appear in three places —
 *
 *   - phlix-server tests/Unit/Hub/RelayMessageFramerWireCorpusTest.php
 *   - phlix-hub    tests/Unit/Relay/FrameWireCorpusTest.php
 *   - phlix-shared tests/Relay/FrameCodecTest.php   (this test)
 *
 * so a wire-format change is always a deliberate three-way disclosure. This
 * file additionally proves the shared canonical matches the digest BEFORE
 * v0.56.0 adoption; at adoption the two repo copies delegate here and the
 * pins stay untouched.
 */
#[CoversClass(FrameCodec::class)]
final class FrameCodecTest extends TestCase
{
    /** md5 over the concatenated encode() output of the full corpus below. */
    public const WIRE_CORPUS_MD5 = '5d8d822e99c0d6a205f7702526f17fc2';

    /** Corpus frame count: 18 enum cases x 4 seq values x 7 payload shapes. */
    public const WIRE_CORPUS_FRAMES = 504;

    /** Total wire bytes of the concatenated corpus (count pin, see class docblock). */
    public const WIRE_CORPUS_BYTES = 151637;

    /** Exact HELLO JSON bytes emitted by encodeHello() on the corpus input. */
    public const HELLO_EXACT =
        '{"type":"hello","enrollment_jwt":"jwt.abc\/def+ghi","server_id":"srv-1"}';

    /** Exact HELLO_ACK JSON bytes emitted by encodeHelloAck() on the corpus input. */
    public const HELLO_ACK_EXACT =
        '{"type":"hello_ack","relay_session_id":"relay-sess-1","tunnel_id":"tunnel-9"}';

    /**
     * The absolute cross-repo wire pin. Iteration order is part of the
     * contract: cases() (declaration order) x corpusSeqs() x corpusPayloads().
     */
    public function test_encode_corpus_matches_cross_repo_wire_digest(): void
    {
        $concat = '';
        $frames = 0;

        foreach (RelayFrameType::cases() as $type) {
            foreach (self::corpusSeqs() as $seq) {
                foreach (self::corpusPayloads() as $payload) {
                    $concat .= FrameCodec::encode($type, $seq, $payload);
                    $frames++;
                }
            }
        }

        $concat .= FrameCodec::encodeHello('jwt.abc/def+ghi', 'srv-1');
        $concat .= FrameCodec::encodeHelloAck('relay-sess-1', 'tunnel-9');

        $this->assertSame(
            self::WIRE_CORPUS_FRAMES,
            $frames,
            'corpus shape changed — the frame count is part of the cross-repo contract',
        );
        $this->assertSame(
            self::WIRE_CORPUS_BYTES,
            strlen($concat),
            'corpus wire volume changed — the byte count is part of the cross-repo contract',
        );
        $this->assertSame(
            self::WIRE_CORPUS_MD5,
            md5($concat),
            'encode() output diverged from the pinned cross-repo wire digest (C-06)',
        );
    }

    /**
     * Human-readable single-frame goldens: [4-byte seq BE][1 type][2 len BE][payload].
     *
     * @return array<string, array{RelayFrameType, int, string, string}>
     */
    public static function goldenVectorProvider(): array
    {
        return [
            'data-hi' => [RelayFrameType::DATA, 0x01020304, 'hi', '010203040500026869'],
            'heartbeat-empty' => [RelayFrameType::HEARTBEAT, 0, '', '00000000060000'],
            'http-cancel-nul-highbit' => [
                RelayFrameType::HTTP_CANCEL,
                0xFFFFFFFF,
                "\x00\xFF",
                'ffffffff12000200ff',
            ],
            'hello-ack-opcode-binary' => [RelayFrameType::HELLO_ACK, 1, 'x', '0000000102000178'],
        ];
    }

    #[DataProvider('goldenVectorProvider')]
    public function test_encode_matches_exact_golden_bytes(
        RelayFrameType $type,
        int $seq,
        string $payload,
        string $expectedHex
    ): void {
        $this->assertSame($expectedHex, bin2hex(FrameCodec::encode($type, $seq, $payload)));
    }

    public function test_encode_hello_emits_exact_json_bytes(): void
    {
        $this->assertSame(self::HELLO_EXACT, FrameCodec::encodeHello('jwt.abc/def+ghi', 'srv-1'));
    }

    public function test_encode_hello_ack_emits_exact_json_bytes(): void
    {
        $this->assertSame(
            self::HELLO_ACK_EXACT,
            FrameCodec::encodeHelloAck('relay-sess-1', 'tunnel-9'),
        );
    }

    /**
     * Oversized-payload rejection keeps its exception TYPE pinned. The message
     * WORDING is deliberately NOT pinned: DESIGN M-21 assigns the server
     * phrasing to this shared home — which the v0.56.0 adoption shipped, so
     * this class is that live home today. The hub in-repo copy keeps its own
     * wording until its delegating leg lands.
     */
    public function test_encode_rejects_oversized_payload_with_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FrameCodec::encode(RelayFrameType::DATA, 1, str_repeat('A', 65536));
    }

    /**
     * Out-of-range channel ids fail fast (S-shared-12). Before the guard,
     * pack('N') silently modulo-wrapped them into a DIFFERENT valid frame
     * value: -1 => FFFFFFFF, 0x100000000 => 00000000 — wire corruption with
     * no signal. Exception TYPE is pinned, wording deliberately not (same
     * M-21 law as the payload arm above).
     *
     * @return array<string, array{int}>
     */
    public static function provide_out_of_range_seq(): array
    {
        return [
            'minus one' => [-1],
            'int min' => [PHP_INT_MIN],
            'uint32 max + 1' => [0x100000000],
            'int max' => [PHP_INT_MAX],
        ];
    }

    #[DataProvider('provide_out_of_range_seq')]
    public function test_encode_rejects_out_of_range_seq_with_invalid_argument(int $seq): void
    {
        $this->expectException(InvalidArgumentException::class);
        FrameCodec::encode(RelayFrameType::DATA, $seq, '');
    }

    /**
     * The guard rejects ONLY values the uint32 field cannot carry: the exact
     * boundaries 0 and 0xFFFFFFFF still encode byte-identically (the corpus
     * pins at {0, 1, 0x7FFFFFFF, 0xFFFFFFFF} cover the rest of the set).
     */
    public function test_encode_still_accepts_the_uint32_boundaries(): void
    {
        $this->assertSame(
            "\xFF\xFF\xFF\xFF\x05\x00\x00",
            FrameCodec::encode(RelayFrameType::DATA, 0xFFFFFFFF, ''),
        );
        $this->assertSame(
            "\x00\x00\x00\x00\x05\x00\x00",
            FrameCodec::encode(RelayFrameType::DATA, 0, ''),
        );
    }

    public function test_encode_accepts_exactly_max_size_payload(): void
    {
        $payload = str_repeat('A', 65535);
        $encoded = FrameCodec::encode(RelayFrameType::DATA, 0xABCD, $payload);

        $this->assertSame(7 + 65535, strlen($encoded));
        $this->assertSame("\x00\x00\xAB\xCD\x05\xFF\xFF", substr($encoded, 0, 7));
        $this->assertSame($payload, substr($encoded, 7));
    }

    /**
     * Full 0x00..0xFF byte ramp as payload — every byte value must survive
     * the encode leg verbatim (binary-unsafe bytes: NUL, CR/LF, high-bit).
     */
    public function test_encode_carries_every_byte_value_verbatim(): void
    {
        $ramp = self::byteRamp();
        $encoded = FrameCodec::encode(RelayFrameType::DATA, 0, $ramp);

        $this->assertSame($ramp, substr($encoded, 7));
    }

    /**
     * The corpus payload set. ORDER AND CONTENT are part of the cross-repo
     * contract — mirror exactly in the server and hub twin tests. NOTE: the
     * truncation bounds 65534/65535 exceed their sources and substr cannot
     * extend a string, so the last two payloads measure 512B/768B (ramp x2
     * and x3), NOT the uint16 caps. Cap-boundary coverage lives in the
     * dedicated max-size/oversized-rejection tests above; changing these
     * bounds would change the pinned digest and belongs to the three-file
     * disclosure ritual, never to a labels fix.
     *
     * @return list<string>
     */
    private static function corpusPayloads(): array
    {
        $ramp = self::byteRamp();

        return [
            '',                                   // zero-length
            'hi',                                 // plain ASCII
            "\x00\xFF\x80\x01\r\n",               // binary-unsafe: NUL, high-bit, CR, LF
            $ramp,                                // 256 bytes (length crosses one byte)
            $ramp . substr($ramp, 0, 255),         // 511 bytes
            substr($ramp . $ramp, 0, 65534),       // 512 bytes (ramp x2 — see docblock)
            substr($ramp . $ramp . $ramp, 0, 65535), // 768 bytes (ramp x3 — see docblock)
        ];
    }

    /**
     * The corpus seq set. ORDER AND CONTENT are part of the cross-repo contract.
     *
     * @return list<int>
     */
    private static function corpusSeqs(): array
    {
        return [0, 1, 0x7FFFFFFF, 0xFFFFFFFF];
    }

    private static function byteRamp(): string
    {
        $ramp = '';
        for ($byte = 0; $byte <= 255; $byte++) {
            $ramp .= chr($byte);
        }

        return $ramp;
    }
}
