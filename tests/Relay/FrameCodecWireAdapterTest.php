<?php

/**
 * Relay Frame Codec Wire Adapter Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use BadMethodCallException;
use InvalidArgumentException;
use Phlix\Shared\Relay\FrameCodec;
use Phlix\Shared\Relay\FrameCodecWireAdapter;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function bin2hex;
use function str_repeat;

/**
 * Pins for {@see FrameCodecWireAdapter} — the canonical instance shape of
 * the shared relay encoder (phlix-shared-050 / S-shared-43).
 *
 * Acceptance law: BYTE-FOR-BYTE delegation to FrameCodec's statics. Two
 * independent anchors enforce it:
 *
 *   1. the full-matrix equality tests below compare every adapter byte to
 *      the static's byte, so the cross-repo wire corpus pinned in
 *      {@see FrameCodecTest} transitively covers the instance surface; and
 *   2. exact golden bytes (hex literals below and the public JSON constants
 *      of {@see FrameCodecTest}) pin the adapter against the wire format
 *      ITSELF — a same-commit edit of the statics' own corpus test could
 *      not quietly move both anchors at once.
 *
 * The decode() refusal is pinned too: it is the load-bearing arm that keeps
 * the "per-repo decoders stay per-repo" design law from eroding.
 */
#[CoversClass(FrameCodecWireAdapter::class)]
final class FrameCodecWireAdapterTest extends TestCase
{
    public function test_is_a_relay_wire_codec_instance(): void
    {
        $this->assertInstanceOf(RelayWireCodecInterface::class, $this->adapter());
    }

    public function test_the_adapter_is_final_and_stateless(): void
    {
        $reflection = new ReflectionClass(FrameCodecWireAdapter::class);

        $this->assertTrue($reflection->isFinal(), 'The canonical adapter must be final.');
        $this->assertSame(
            [],
            $reflection->getProperties(),
            'Stateless by contract: any instance is interchangeable.',
        );
    }

    /**
     * Exhaustive delegation equality on the binary leg: every frame type x
     * the channel-id boundary set x four payload shapes (incl. the exact
     * 65535-byte cap), each adapter byte identical to the static's byte.
     */
    public function test_encode_delegates_byte_identically_over_the_full_matrix(): void
    {
        $adapter = $this->adapter();

        foreach (RelayFrameType::cases() as $type) {
            foreach ([0, 1, 0x7FFFFFFF, 0xFFFFFFFF] as $seq) {
                foreach (['', 'hi', "\x00\xFF\r\n", str_repeat('A', 65535)] as $payload) {
                    $this->assertSame(
                        FrameCodec::encode($type, $seq, $payload),
                        $adapter->encode($type, $seq, $payload),
                        'encode() must be pure delegation, byte for byte.',
                    );
                }
            }
        }
    }

    /**
     * Independent second anchor: exact wire bytes, double-derived from the
     * format law [4B channel id BE][1B type][2B len BE][payload] (same hex
     * family as the {@see FrameCodecTest} goldens, pinned here for the
     * instance surface).
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
        ];
    }

    #[DataProvider('goldenVectorProvider')]
    public function test_encode_matches_exact_golden_bytes(
        RelayFrameType $type,
        int $seq,
        string $payload,
        string $expectedHex
    ): void {
        $this->assertSame($expectedHex, bin2hex($this->adapter()->encode($type, $seq, $payload)));
    }

    public function test_encode_hello_delegates_to_the_pinned_json_bytes(): void
    {
        $adapter = $this->adapter();

        $this->assertSame(
            FrameCodec::encodeHello('jwt.abc/def+ghi', 'srv-1'),
            $adapter->encodeHello('jwt.abc/def+ghi', 'srv-1'),
        );
        $this->assertSame(
            FrameCodecTest::HELLO_EXACT,
            $adapter->encodeHello('jwt.abc/def+ghi', 'srv-1'),
            'The adapter must emit the corpus-pinned HELLO bytes verbatim.',
        );
    }

    public function test_encode_hello_ack_delegates_to_the_pinned_json_bytes(): void
    {
        $adapter = $this->adapter();

        $this->assertSame(
            FrameCodec::encodeHelloAck('relay-sess-1', 'tunnel-9'),
            $adapter->encodeHelloAck('relay-sess-1', 'tunnel-9'),
        );
        $this->assertSame(
            FrameCodecTest::HELLO_ACK_EXACT,
            $adapter->encodeHelloAck('relay-sess-1', 'tunnel-9'),
            'The adapter must emit the corpus-pinned HELLO_ACK bytes verbatim.',
        );
    }

    /**
     * The uint32 channel-id guard added by S-shared-12 lives in the static
     * and must bind on the instance path too — the adapter must not be a
     * bypass that re-wraps out-of-range ids. Exception TYPE pinned, wording
     * deliberately not (same M-21 law as the static's own tests).
     *
     * @return array<string, array{int}>
     */
    public static function provide_out_of_range_seq(): array
    {
        return [
            'minus one' => [-1],
            'uint32 max + 1' => [0x100000000],
            'int max' => [PHP_INT_MAX],
        ];
    }

    #[DataProvider('provide_out_of_range_seq')]
    public function test_encode_propagates_the_uint32_range_guard(int $seq): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->encode(RelayFrameType::DATA, $seq, '');
    }

    public function test_encode_propagates_the_payload_cap_guard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->encode(RelayFrameType::DATA, 1, str_repeat('A', 65536));
    }

    public function test_encode_still_accepts_the_uint32_boundaries(): void
    {
        $adapter = $this->adapter();

        $this->assertSame(
            "\xFF\xFF\xFF\xFF\x05\x00\x00",
            $adapter->encode(RelayFrameType::DATA, 0xFFFFFFFF, ''),
        );
        $this->assertSame(
            "\x00\x00\x00\x00\x05\x00\x00",
            $adapter->encode(RelayFrameType::DATA, 0, ''),
        );
    }

    /**
     * decode() must fail loud, never silently adopt one role's decoder
     * semantics. Type AND the word 'decode' in the message are pinned (the
     * message is this arm's whole contract); full wording stays free.
     */
    public function test_decode_fails_loud_instead_of_adopting_a_role(): void
    {
        $thrown = null;

        try {
            $this->adapter()->decode("\x00\x00\x00\x00\x06\x00\x00");
        } catch (BadMethodCallException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(
            BadMethodCallException::class,
            $thrown,
            'A well-formed HEARTBEAT frame must still be refused: the refusal is structural.',
        );
        $this->assertStringContainsString('decode', $thrown->getMessage());
    }

    public function test_decode_refuses_incomplete_input_on_the_same_loud_path(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->adapter()->decode('short');
    }

    private function adapter(): FrameCodecWireAdapter
    {
        return new FrameCodecWireAdapter();
    }
}
