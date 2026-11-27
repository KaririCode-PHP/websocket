<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Handshake;

use KaririCode\WebSocket\Exception\HandshakeException;
use KaririCode\WebSocket\Exception\InvalidConfigurationException;
use KaririCode\WebSocket\Exception\WsCode;
use KaririCode\WebSocket\Handshake\HandshakeNegotiator;
use KaririCode\WebSocket\Handshake\HandshakeRequest;
use KaririCode\WebSocket\Handshake\HandshakeResponse;
use KaririCode\WebSocket\Tests\Support\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HandshakeNegotiator::class)]
#[CoversClass(HandshakeRequest::class)]
#[CoversClass(HandshakeResponse::class)]
#[CoversClass(HandshakeException::class)]
final class HandshakeNegotiatorTest extends TestCase
{
    // ── Accepted handshakes ──────────────────────────────────────────────

    #[Test]
    public function aValidHandshakeGets101WithTheRfcAcceptValue(): void
    {
        $negotiator = new HandshakeNegotiator();

        $response = $negotiator->negotiate($negotiator->parseRequest(Client::handshake()));

        self::assertSame("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . Client::ACCEPT . "\r\n\r\n", $response->head);
        self::assertNull($response->subprotocol);
        self::assertSame(Client::ACCEPT, HandshakeNegotiator::acceptKey(Client::KEY));
    }

    #[Test]
    public function theRequestKeepsTargetAndFields(): void
    {
        $request = (new HandshakeNegotiator())->parseRequest(Client::handshake(['Cookie' => 'session=abc']));

        self::assertSame('/chat?room=7', $request->target);
        self::assertSame('/chat', $request->path());
        self::assertSame('room=7', $request->query());
        self::assertSame('session=abc', $request->header('COOKIE'));
        self::assertNull($request->header('x-absent'));
        self::assertSame(['server.example.com'], $request->headers['host']);
    }

    #[Test]
    public function aTargetWithoutQueryIsAllPath(): void
    {
        $request = new HandshakeRequest('/plain', []);

        self::assertSame('/plain', $request->path());
        self::assertSame('', $request->query());
    }

    #[Test]
    public function repeatedFieldsAreJoinedInOrder(): void
    {
        $head = "GET / HTTP/1.1\r\nHost: a\r\nSec-WebSocket-Protocol: one\r\nSec-WebSocket-Protocol: two, three\r\n\r\n";

        $request = (new HandshakeNegotiator())->parseRequest($head);

        self::assertSame('one, two, three', $request->header('sec-websocket-protocol'));
    }

    #[Test]
    public function tokenListsMatchWithoutRegardToCaseOrPosition(): void
    {
        $negotiator = new HandshakeNegotiator();
        $head = Client::handshake(['Upgrade' => 'h2c, WebSocket', 'Connection' => 'keep-alive, UPGRADE']);

        self::assertStringStartsWith('HTTP/1.1 101', $negotiator->negotiate($negotiator->parseRequest($head))->head);
    }

    #[Test]
    public function theFirstOfferedSubprotocolTheServerSpeaksIsSelected(): void
    {
        $negotiator = new HandshakeNegotiator(subprotocols: ['chat.v2', 'chat.v1']);

        $response = $negotiator->negotiate($negotiator->parseRequest(Client::handshake(['Sec-WebSocket-Protocol' => 'json, chat.v1 ,chat.v2'])));
        $none = $negotiator->negotiate($negotiator->parseRequest(Client::handshake(['Sec-WebSocket-Protocol' => 'json'])));
        $unasked = $negotiator->negotiate($negotiator->parseRequest(Client::handshake()));

        self::assertSame('chat.v1', $response->subprotocol);
        self::assertStringContainsString("\r\nSec-WebSocket-Protocol: chat.v1\r\n\r\n", $response->head);
        self::assertNull($none->subprotocol);
        self::assertStringNotContainsString('Sec-WebSocket-Protocol', $none->head);
        self::assertNull($unasked->subprotocol);
    }

    #[Test]
    public function subprotocolsCompareExactly(): void
    {
        $negotiator = new HandshakeNegotiator(subprotocols: ['chat']);

        self::assertNull($negotiator->negotiate($negotiator->parseRequest(Client::handshake(['Sec-WebSocket-Protocol' => 'CHAT'])))->subprotocol);
    }

    // ── The request head ─────────────────────────────────────────────────

    #[Test]
    public function theHeadEndsAtTheBlankLine(): void
    {
        $negotiator = new HandshakeNegotiator();
        $head = Client::handshake();

        self::assertNull($negotiator->head(substr($head, 0, -1)));
        self::assertNull($negotiator->head(''));
        self::assertSame($head, $negotiator->head($head . "\x81\x80frame bytes"));
    }

    #[Test]
    public function aHeadAboveTheLimitIsRefusedWithOrWithoutItsEnd(): void
    {
        $negotiator = new HandshakeNegotiator(maxRequestSize: 64);
        $long = Client::handshake();

        self::assertSame("GET / HTTP/1.1\r\nHost: a\r\n\r\n", $negotiator->head("GET / HTTP/1.1\r\nHost: a\r\n\r\n"));

        foreach ([str_repeat('a', 65), $long, substr($long, 0, 80)] as $buffer) {
            self::assertRefused(WsCode::HandshakeTooLarge, 431, static fn() => $negotiator->head($buffer));
        }

        self::assertRefused(WsCode::HandshakeTooLarge, 431, static fn() => $negotiator->parseRequest($long));
        self::assertNull($negotiator->head(str_repeat('a', 64)), 'exactly the limit is still allowed to grow into a head');

        $exact = "GET / HTTP/1.1\r\nHost: a\r\n\r\n";
        self::assertSame('a', (new HandshakeNegotiator(maxRequestSize: strlen($exact)))->parseRequest($exact)->header('host'), 'a head of exactly the limit is read');
    }

    #[Test]
    public function aFieldValueMayFollowTheColonWithoutASpace(): void
    {
        $request = (new HandshakeNegotiator())->parseRequest("GET / HTTP/1.1\r\nHost:example.com\r\n\r\n");

        self::assertSame('example.com', $request->header('host'));
    }

    // ── Refused requests ─────────────────────────────────────────────────

    /**
     * @return iterable<string, array{string, WsCode, int}>
     */
    public static function malformedRequests(): iterable
    {
        yield 'POST' => [Client::handshake(requestLine: 'POST /chat HTTP/1.1'), WsCode::MethodNotGet, 400];
        yield 'lower-case get' => [Client::handshake(requestLine: 'get /chat HTTP/1.1'), WsCode::MethodNotGet, 400];
        yield 'HTTP/1.0' => [Client::handshake(requestLine: 'GET /chat HTTP/1.0'), WsCode::UnsupportedHttpVersion, 400];
        yield 'HTTP/2' => [Client::handshake(requestLine: 'GET /chat HTTP/2'), WsCode::UnsupportedHttpVersion, 400];
        yield 'double space' => [Client::handshake(requestLine: 'GET  /chat HTTP/1.1'), WsCode::MalformedRequestLine, 400];
        yield 'no version' => [Client::handshake(requestLine: 'GET /chat'), WsCode::MalformedRequestLine, 400];
        yield 'method not a token' => [Client::handshake(requestLine: 'G(T /chat HTTP/1.1'), WsCode::MalformedRequestLine, 400];
        yield 'absolute-form target' => [Client::handshake(requestLine: 'GET http://a/chat HTTP/1.1'), WsCode::MalformedRequestLine, 400];
        yield 'asterisk target' => [Client::handshake(requestLine: 'GET * HTTP/1.1'), WsCode::MalformedRequestLine, 400];
        yield 'control character in the target' => [Client::handshake(requestLine: "GET /a\x01 HTTP/1.1"), WsCode::MalformedRequestLine, 400];
        yield 'empty request line' => [Client::handshake(requestLine: ''), WsCode::MalformedRequestLine, 400];
        yield 'field without colon' => ["GET / HTTP/1.1\r\nHost a\r\n\r\n", WsCode::MalformedHeader, 400];
        yield 'space before the colon' => ["GET / HTTP/1.1\r\nHost : a\r\n\r\n", WsCode::MalformedHeader, 400];
        yield 'folded line' => ["GET / HTTP/1.1\r\nHost: a\r\n continued\r\n\r\n", WsCode::MalformedHeader, 400];
        yield 'control character in a value' => ["GET / HTTP/1.1\r\nHost: a\x7F\r\n\r\n", WsCode::MalformedHeader, 400];
        yield 'bare line feed in a value' => ["GET / HTTP/1.1\r\nHost: a\nX: y\r\n\r\n", WsCode::MalformedHeader, 400];
        yield 'no blank line' => ["GET / HTTP/1.1\r\nHost: a\r\n", WsCode::MalformedHeader, 400];
        yield 'blank line inside' => ["GET / HTTP/1.1\r\nHost: a\r\n\r\nX: y\r\n\r\n", WsCode::MalformedHeader, 400];
        yield 'two Host fields' => ["GET / HTTP/1.1\r\nHost: a\r\nHost: b\r\n\r\n", WsCode::DuplicateHeader, 400];
        yield 'two keys' => [str_replace("\r\n\r\n", "\r\nSec-WebSocket-Key: " . Client::KEY . "\r\n\r\n", Client::handshake()), WsCode::DuplicateHeader, 400];
        yield 'two versions' => [str_replace("\r\n\r\n", "\r\nsec-websocket-version: 13\r\n\r\n", Client::handshake()), WsCode::DuplicateHeader, 400];
        yield 'two origins' => [str_replace("\r\n\r\n", "\r\nOrigin: https://evil.example\r\n\r\n", Client::handshake()), WsCode::DuplicateHeader, 400];
    }

    #[Test]
    #[DataProvider('malformedRequests')]
    public function aMalformedRequestIsRefused(string $head, WsCode $code, int $status): void
    {
        self::assertRefused($code, $status, static fn() => (new HandshakeNegotiator())->parseRequest($head));
    }

    /**
     * @return iterable<string, array{array<string, string|null>, WsCode, int}>
     */
    public static function invalidHandshakes(): iterable
    {
        yield 'no Host' => [['Host' => null], WsCode::MissingHost, 400];
        yield 'empty Host' => [['Host' => ''], WsCode::MissingHost, 400];
        yield 'no Upgrade' => [['Upgrade' => null], WsCode::MissingUpgrade, 400];
        yield 'another upgrade' => [['Upgrade' => 'h2c'], WsCode::MissingUpgrade, 400];
        yield 'websocket as part of a word' => [['Upgrade' => 'websockets'], WsCode::MissingUpgrade, 400];
        yield 'no Connection' => [['Connection' => null], WsCode::MissingConnectionUpgrade, 400];
        yield 'Connection without upgrade' => [['Connection' => 'keep-alive'], WsCode::MissingConnectionUpgrade, 400];
        yield 'upgrade as part of a word' => [['Connection' => 'NotUpgrade'], WsCode::MissingConnectionUpgrade, 400];
        yield 'version 8' => [['Sec-WebSocket-Version' => '8'], WsCode::UnsupportedVersion, 426];
        yield 'no version' => [['Sec-WebSocket-Version' => null], WsCode::UnsupportedVersion, 426];
        yield 'no key' => [['Sec-WebSocket-Key' => null], WsCode::InvalidKey, 400];
        yield 'short key' => [['Sec-WebSocket-Key' => 'c2hvcnQ='], WsCode::InvalidKey, 400];
        yield 'key of 17 bytes' => [['Sec-WebSocket-Key' => base64_encode(str_repeat('k', 17))], WsCode::InvalidKey, 400];
        yield 'key not base64' => [['Sec-WebSocket-Key' => '!!!!!!!!!!!!!!!!!!!!!!=='], WsCode::InvalidKey, 400];
    }

    /**
     * @param array<string, string|null> $headers
     */
    #[Test]
    #[DataProvider('invalidHandshakes')]
    public function anInvalidHandshakeIsRefused(array $headers, WsCode $code, int $status): void
    {
        $negotiator = new HandshakeNegotiator();
        $request = $negotiator->parseRequest(Client::handshake($headers));

        self::assertRefused($code, $status, static fn() => $negotiator->negotiate($request));
    }

    #[Test]
    public function theOriginAllowListIsEnforced(): void
    {
        $negotiator = new HandshakeNegotiator(allowedOrigins: ['https://App.Example.com', 'http://localhost:8080']);
        $allowed = self::request($negotiator, []);
        $local = self::request($negotiator, ['Origin' => 'http://localhost:8080']);
        $evil = self::request($negotiator, ['Origin' => 'https://evil.example']);
        $none = self::request($negotiator, ['Origin' => null]);

        self::assertStringStartsWith('HTTP/1.1 101', $negotiator->negotiate($allowed)->head, 'origins compare without regard to case');
        self::assertStringStartsWith('HTTP/1.1 101', $negotiator->negotiate($local)->head);
        self::assertRefused(WsCode::OriginRejected, 403, static fn() => $negotiator->negotiate($evil));
        self::assertRefused(WsCode::OriginRejected, 403, static fn() => $negotiator->negotiate($none));
        self::assertStringStartsWith('HTTP/1.1 101', (new HandshakeNegotiator())->negotiate($none)->head, 'no list, no check');
    }

    // ── Rejections ───────────────────────────────────────────────────────

    #[Test]
    public function aRejectionIsAFixedResponse(): void
    {
        self::assertSame("HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n", HandshakeNegotiator::rejection(400));
        self::assertSame("HTTP/1.1 403 Forbidden\r\nConnection: close\r\nContent-Length: 0\r\n\r\n", HandshakeNegotiator::rejection(403));
        self::assertSame("HTTP/1.1 426 Upgrade Required\r\nSec-WebSocket-Version: 13\r\nConnection: close\r\nContent-Length: 0\r\n\r\n", HandshakeNegotiator::rejection(426));
        self::assertSame("HTTP/1.1 431 Request Header Fields Too Large\r\nConnection: close\r\nContent-Length: 0\r\n\r\n", HandshakeNegotiator::rejection(431));
        self::assertSame(HandshakeNegotiator::rejection(400), HandshakeNegotiator::rejection(599), 'an unknown status becomes 400');
    }

    // ── Configuration ────────────────────────────────────────────────────

    #[Test]
    public function theConfigurationIsValidated(): void
    {
        foreach ([
            [WsCode::InvalidOrigin, static fn() => new HandshakeNegotiator(allowedOrigins: ['https://app.example.com/'])],
            [WsCode::InvalidOrigin, static fn() => new HandshakeNegotiator(allowedOrigins: ['null'])],
            [WsCode::InvalidSubprotocol, static fn() => new HandshakeNegotiator(subprotocols: ['chat v2'])],
            [WsCode::NonPositiveLimit, static fn() => new HandshakeNegotiator(maxRequestSize: 0)],
        ] as [$code, $build]) {
            try {
                $build();
                self::fail('an invalid configuration was accepted');
            } catch (InvalidConfigurationException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }

        $negotiator = new HandshakeNegotiator(['https://a.example'], ['chat'], 1024);
        self::assertSame(['https://a.example'], $negotiator->allowedOrigins);
        self::assertSame(['chat'], $negotiator->subprotocols);
        self::assertSame(1024, $negotiator->maxRequestSize);
        self::assertSame(8192, (new HandshakeNegotiator())->maxRequestSize);
        self::assertSame(1, (new HandshakeNegotiator(maxRequestSize: 1))->maxRequestSize);
    }

    /**
     * @param array<string, string|null> $headers
     */
    private static function request(HandshakeNegotiator $negotiator, array $headers): HandshakeRequest
    {
        return $negotiator->parseRequest(Client::handshake($headers));
    }

    private static function assertRefused(WsCode $code, int $status, \Closure $operation): void
    {
        try {
            $operation();
            self::fail('the handshake was accepted');
        } catch (HandshakeException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($status, $e->status);
        }
    }
}
