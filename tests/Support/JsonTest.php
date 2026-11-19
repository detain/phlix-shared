<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Support;

use InvalidArgumentException;
use JsonException;
use Phlix\Shared\Support\Json;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Json Policy - the Throwing Default, Both Wire Baselines, the Depth Law
 *
 * Pins the M-13 resolution: new shared sites encode strictly (slashes and
 * Unicode unescaped, failure THROWS - the D-05 hub posture chosen as the
 * default); the server's PRETTY HTTP set and the compact sets each keep an
 * explicit seam so no wire bytes move silently at adoption.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 * @package   Phlix\Shared\Tests\Support
 * @since     0.56.0
 */
#[CoversClass(Json::class)]
final class JsonTest extends TestCase
{
    public function test_encode_default_leaves_slashes_and_unicode_raw(): void
    {
        self::assertSame(
            '{"url":"https://hub.example/a/b","who":"café"}',
            Json::encode(['url' => 'https://hub.example/a/b', 'who' => 'café']),
        );
    }

    public function test_encode_throws_on_unencodable_values(): void
    {
        $this->expectException(JsonException::class);

        Json::encode("\xB0\x0Finvalid-utf8");
    }

    public function test_encode_flags_argument_overrides_the_policy_wholesale(): void
    {
        // Adoption-site discipline: passing the repo's live set verbatim is
        // how byte-identity is preserved (server:205 / hub Response shapes).
        self::assertSame(
            '{"url":"https:\/\/x\/y","who":"caf\u00e9"}',
            Json::encode(['url' => 'https://x/y', 'who' => 'café'], JSON_THROW_ON_ERROR),
        );
    }

    public function test_encode_pretty_is_the_server_http_wire_set_verbatim(): void
    {
        $data = ['a' => 1, 'b' => ['c' => 'd/e']];

        self::assertSame(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            Json::encodePretty($data),
        );
    }

    public function test_encode_pretty_output_is_the_expected_expanded_shape(): void
    {
        self::assertSame(
            "{\n    \"error\": \"Nope\",\n    \"code\": \"bad\"\n}",
            Json::encodePretty(['error' => 'Nope', 'code' => 'bad']),
        );
    }

    public function test_encode_pretty_throws_on_unencodable_values(): void
    {
        $resource = fopen('php://memory', 'rb');

        if (!is_resource($resource)) {
            self::fail('test fixture: in-memory resource expected');
        }

        try {
            $this->expectException(JsonException::class);

            Json::encodePretty(['r' => $resource]);
        } finally {
            fclose($resource);
        }
    }

    public function test_decode_inbound_parses_associative(): void
    {
        self::assertSame(
            ['a' => 1, 'b' => ['c' => true, 'd' => null]],
            Json::decodeInbound('{"a":1,"b":{"c":true,"d":null}}'),
        );
    }

    public function test_decode_inbound_maps_malformed_to_null(): void
    {
        self::assertNull(Json::decodeInbound('{'));
        self::assertNull(Json::decodeInbound('{"a":}'));
        self::assertNull(Json::decodeInbound(''));
        self::assertNull(Json::decodeInbound("\xB0\x0F"));
    }

    public function test_decode_inbound_rethrows_only_the_depth_class(): void
    {
        $deep = str_repeat('[', 600) . str_repeat(']', 600);

        $this->expectException(JsonException::class);

        Json::decodeInbound($deep);
    }

    public function test_decode_inbound_honours_a_stricter_per_site_depth(): void
    {
        // The F-14 law: the const is only the DEFAULT; a call site that owes
        // depth 3 gets the throw at a 4-level document, not at the global
        // cap. (PHP counts the root: k bracket levels need depth k+1.)
        $nested3 = '[[[1]]]';

        self::assertSame([[[1]]], Json::decodeInbound($nested3, 4));

        $this->expectException(JsonException::class);

        Json::decodeInbound($nested3, 3);
    }

    public function test_decode_inbound_legitimate_scalars_survive_the_null_merge(): void
    {
        // 'null' decodes to null exactly like malformed input does - the
        // caller's bad-input seam must live at the transport layer, not
        // pretend to distinguish here. Pinned, disclosed.
        self::assertNull(Json::decodeInbound('null'));
        self::assertSame(0, Json::decodeInbound('0'));
        self::assertSame(false, Json::decodeInbound('false'));
        self::assertSame('x', Json::decodeInbound('"x"'));
    }

    public function test_max_inbound_depth_is_php_default(): void
    {
        self::assertSame(512, Json::MAX_INBOUND_DEPTH);
    }

    public function test_decode_inbound_rejects_a_ceiling_below_one(): void
    {
        // Fail-fast: PHP's "depth 0 or below means unlimited" is refused -
        // an inbound guard with no ceiling is not a guard.
        $this->expectException(InvalidArgumentException::class);

        Json::decodeInbound('{"a":1}', 0);
    }
}
