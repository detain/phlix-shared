<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Http;

use Phlix\Shared\Http\WebSocketHandshake;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * WebSocketHandshake Test.
 *
 * Characterization pins for v0.57 leg 0.10 M-20 (A-07/C-10). String-level
 * kernels lifted from phlix-server `src/Server/WebSocket/SyncPlayAuthMiddleware.php`
 * (`offersBearerSubprotocol` :494, `bearerSubprotocolToken` :519, consts :68/:77)
 * and phlix-hub `src/SyncPlay/SyncPlayRelayWorker.php`
 * (`negotiatedSubprotocolEcho` :1013, consts :223/:231). The Workerman
 * Request adapter (`upgradeHeader`, byte-identical twins) and the carrier
 * POLICY (server legacy `?token=` behind QueryTokenGatePolicy vs hub
 * bearer-only S237) intentionally stay per-repo — §5.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
#[CoversClass(WebSocketHandshake::class)]
final class WebSocketHandshakeTest extends TestCase
{
    public function testBearerMarkerConstantIsTheWireLiteral(): void
    {
        self::assertSame('bearer', WebSocketHandshake::BEARER_SUBPROTOCOL);
        self::assertSame('Sec-WebSocket-Protocol: bearer', WebSocketHandshake::BEARER_SUBPROTOCOL_ECHO);
    }

    public function testOffersMatchesPerEntryAfterTrim(): void
    {
        self::assertTrue(WebSocketHandshake::offersBearerSubprotocol('bearer'));
        self::assertTrue(WebSocketHandshake::offersBearerSubprotocol('chat, bearer'));
        self::assertTrue(WebSocketHandshake::offersBearerSubprotocol(' bearer , chat'));
        self::assertTrue(WebSocketHandshake::offersBearerSubprotocol('bearer,bearer'));
    }

    public function testNearMissesAreNotOffers(): void
    {
        // RFC 7230 token equality: `bearer-chat` is a DIFFERENT protocol id.
        self::assertFalse(WebSocketHandshake::offersBearerSubprotocol('bearer-chat'));
        self::assertFalse(WebSocketHandshake::offersBearerSubprotocol(''));
        self::assertFalse(WebSocketHandshake::offersBearerSubprotocol('chat'));
        self::assertFalse(WebSocketHandshake::offersBearerSubprotocol('Bearer'));
        self::assertFalse(WebSocketHandshake::offersBearerSubprotocol('chat; q=1, bearer-chat'));
    }

    public function testTokenIsFirstNonBearerEntry(): void
    {
        self::assertSame('jwt-abc', WebSocketHandshake::bearerSubprotocolToken('bearer, jwt-abc'));
        self::assertSame('jwt-abc', WebSocketHandshake::bearerSubprotocolToken(' bearer,jwt-abc '));
        self::assertSame('chat', WebSocketHandshake::bearerSubprotocolToken('bearer, chat, jwt-abc'));
        self::assertNull(WebSocketHandshake::bearerSubprotocolToken('bearer'));
        self::assertNull(WebSocketHandshake::bearerSubprotocolToken('bearer, '));
        self::assertNull(WebSocketHandshake::bearerSubprotocolToken(''));
    }

    public function testTokenKernelIsDumbOnPurpose(): void
    {
        // The kernel does NOT gate on the marker being offered: the first
        // non-bearer entry is returned verbatim (server calls it only behind
        // the offers gate; pinning the raw twin behavior).
        self::assertSame('chat', WebSocketHandshake::bearerSubprotocolToken('chat, x'));
    }

    public function testEchoLineSelectsOnlyAnOfferedId(): void
    {
        // RFC 6455 §4.2.2: at most one offered id may be echoed.
        self::assertSame(
            'Sec-WebSocket-Protocol: bearer',
            WebSocketHandshake::negotiatedSubprotocolEchoLine('chat, bearer', 'bearer'),
        );
        self::assertSame(
            'Sec-WebSocket-Protocol: bearer',
            WebSocketHandshake::negotiatedSubprotocolEchoLine(' bearer ', 'bearer'),
        );
    }

    public function testEchoLineRefusesUnofferedAndEmpty(): void
    {
        // Empty string = no echo; a token sharing the carrier is never chosen,
        // and echoing what was not offered would fail the client (§4.1).
        self::assertSame('', WebSocketHandshake::negotiatedSubprotocolEchoLine('chat', 'bearer'));
        self::assertSame('', WebSocketHandshake::negotiatedSubprotocolEchoLine('', 'bearer'));
        self::assertSame('', WebSocketHandshake::negotiatedSubprotocolEchoLine('bearer-chat', 'bearer'));
    }

    public function testEchoLineShapeEqualsTheConst(): void
    {
        self::assertSame(
            WebSocketHandshake::BEARER_SUBPROTOCOL_ECHO,
            WebSocketHandshake::negotiatedSubprotocolEchoLine('bearer', 'bearer'),
        );
    }
}
