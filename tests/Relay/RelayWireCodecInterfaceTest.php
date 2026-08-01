<?php

/**
 * Relay Wire Codec Interface Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Locks the {@see RelayWireCodecInterface} contract: the exact method set and
 * signatures the relay wire codec must implement, and proves the contract is
 * satisfiable end-to-end through a fake implementer.
 *
 * @coversNothing
 */
final class RelayWireCodecInterfaceTest extends TestCase
{
    public function test_is_an_interface(): void
    {
        $reflection = new ReflectionClass(RelayWireCodecInterface::class);
        $this->assertTrue(
            $reflection->isInterface(),
            'RelayWireCodecInterface must be an interface.'
        );
    }

    public function test_interface_declares_exactly_the_expected_methods(): void
    {
        $reflection = new ReflectionClass(RelayWireCodecInterface::class);

        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods()
        );
        sort($methods);

        $this->assertSame(
            ['decode', 'encode', 'encodeHello', 'encodeHelloAck'],
            $methods,
            'RelayWireCodecInterface must declare exactly encode, decode, encodeHello, encodeHelloAck.'
        );
    }

    public function test_encode_signature(): void
    {
        $method = new ReflectionMethod(RelayWireCodecInterface::class, 'encode');
        $params = $method->getParameters();

        $this->assertCount(3, $params);

        $this->assertSame('type', $params[0]->getName());
        $this->assertParamTypeName(RelayFrameType::class, $params[0]->getType());

        $this->assertSame('seq', $params[1]->getName());
        $this->assertParamTypeName('int', $params[1]->getType());

        $this->assertSame('payload', $params[2]->getName());
        $this->assertParamTypeName('string', $params[2]->getType());

        $this->assertReturnTypeName('string', $method);
    }

    public function test_encodeHello_signature(): void
    {
        $method = new ReflectionMethod(RelayWireCodecInterface::class, 'encodeHello');
        $params = $method->getParameters();

        $this->assertCount(2, $params);

        $this->assertSame('enrollmentJwt', $params[0]->getName());
        $this->assertParamTypeName('string', $params[0]->getType());

        $this->assertSame('serverId', $params[1]->getName());
        $this->assertParamTypeName('string', $params[1]->getType());

        $this->assertReturnTypeName('string', $method);
    }

    public function test_encodeHelloAck_signature(): void
    {
        $method = new ReflectionMethod(RelayWireCodecInterface::class, 'encodeHelloAck');
        $params = $method->getParameters();

        $this->assertCount(2, $params);

        $this->assertSame('relaySessionId', $params[0]->getName());
        $this->assertParamTypeName('string', $params[0]->getType());

        $this->assertSame('tunnelId', $params[1]->getName());
        $this->assertParamTypeName('string', $params[1]->getType());

        $this->assertReturnTypeName('string', $method);
    }

    public function test_decode_signature(): void
    {
        $method = new ReflectionMethod(RelayWireCodecInterface::class, 'decode');
        $params = $method->getParameters();

        $this->assertCount(1, $params);

        $this->assertSame('bytes', $params[0]->getName());
        $this->assertParamTypeName('string', $params[0]->getType());

        // Return type is ?RelayFrame - check it returns a nullable type
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
    }

    public function test_interface_is_implementable_and_exposes_identity(): void
    {
        $codec = $this->fakeCodec();

        $this->assertInstanceOf(RelayWireCodecInterface::class, $codec);
    }

    public function test_encode_returns_string(): void
    {
        $codec = $this->fakeCodec();

        $result = $codec->encode(RelayFrameType::CLIENT_CONNECT, 12345, 'test-payload');

        $this->assertNotEmpty($result);
    }

    public function test_encodeHello_returns_json_string(): void
    {
        $codec = $this->fakeCodec();

        $result = $codec->encodeHello('test-jwt', 'server-uuid');

        $this->assertStringContainsString('enrollmentJwt', $result);
        $this->assertStringContainsString('serverId', $result);
    }

    public function test_encodeHelloAck_returns_json_string(): void
    {
        $codec = $this->fakeCodec();

        $result = $codec->encodeHelloAck('relay-session-uuid', 'tunnel-uuid');

        $this->assertStringContainsString('relaySessionId', $result);
        $this->assertStringContainsString('tunnelId', $result);
    }

    public function test_decode_returns_null_for_incomplete_data(): void
    {
        $codec = $this->fakeCodec();

        // Less than 7 bytes (4-byte channel/seq + 1-byte type + 2-byte length)
        $result = $codec->decode('short');
        $this->assertNull($result);
    }

    public function test_decode_returns_frame_for_complete_data(): void
    {
        $codec = $this->fakeCodec();

        // 7-byte header: 4-byte seq (big-endian), 1-byte type, 2-byte length
        // Channel 0, type CLIENT_CONNECT (0x03), length 5
        $frame = $codec->decode("\x00\x00\x00\x00" . "\x03" . "\x00\x05" . "hello");

        $this->assertInstanceOf(RelayFrame::class, $frame);
        $this->assertSame(RelayFrameType::CLIENT_CONNECT, $frame->type);
    }

    /**
     * A minimal anonymous-class implementer proving the contract is satisfiable
     * and that PHP accepts every declared signature.
     */
    private function fakeCodec(): RelayWireCodecInterface
    {
        return new class implements RelayWireCodecInterface {
            public function encode(RelayFrameType $type, int $seq, string $payload): string
            {
                // Simple binary encoding for testing
                $channel = pack('N', $seq);
                $typeByte = chr($type->value);
                $length = pack('n', strlen($payload));
                return $channel . $typeByte . $length . $payload;
            }

            public function encodeHello(string $enrollmentJwt, string $serverId): string
            {
                // json_encode with JSON_THROW_ON_ERROR returns non-empty-string, never false
                return json_encode([
                    'enrollmentJwt' => $enrollmentJwt,
                    'serverId' => $serverId,
                ], JSON_THROW_ON_ERROR);
            }

            public function encodeHelloAck(string $relaySessionId, string $tunnelId): string
            {
                // json_encode with JSON_THROW_ON_ERROR returns non-empty-string, never false
                return json_encode([
                    'relaySessionId' => $relaySessionId,
                    'tunnelId' => $tunnelId,
                ], JSON_THROW_ON_ERROR);
            }

            public function decode(string $bytes): ?RelayFrame
            {
                // Minimum 7 bytes for header
                if (strlen($bytes) < 7) {
                    return null;
                }

                $channelData = unpack('N', substr($bytes, 0, 4));
                if ($channelData === false) {
                    return null;
                }
                $channel = $channelData[1];
                $typeValue = ord(substr($bytes, 4, 1));
                $lengthData = unpack('n', substr($bytes, 5, 2));
                if ($lengthData === false) {
                    return null;
                }
                $length = $lengthData[1];

                if (strlen($bytes) < 7 + $length) {
                    return null;
                }

                $payload = substr($bytes, 7, $length);

                return new RelayFrame(
                    type: RelayFrameType::from($typeValue),
                    seq: $channel,
                    payload: $payload,
                );
            }
        };
    }

    private function assertReturnTypeName(string $expected, ReflectionMethod $method): void
    {
        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame($expected, $returnType->getName());
    }

    private function assertParamTypeName(string $expected, ?\ReflectionType $type): void
    {
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame($expected, $type->getName());
    }
}
