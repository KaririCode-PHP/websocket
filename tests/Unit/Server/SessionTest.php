<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Server;

use KaririCode\WebSocket\Connection\ConnectionPool;
use KaririCode\WebSocket\Connection\WebSocketConnection;
use KaririCode\WebSocket\Contract\Connection;
use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\ConnectionState;
use KaririCode\WebSocket\Exception\FrameException;
use KaririCode\WebSocket\Exception\WsCode;
use KaririCode\WebSocket\Handshake\HandshakeNegotiator;
use KaririCode\WebSocket\Message\Message;
use KaririCode\WebSocket\Server\ServerContext;
use KaririCode\WebSocket\Server\ServerOptions;
use KaririCode\WebSocket\Server\Session;
use KaririCode\WebSocket\Tests\Support\Client;
use KaririCode\WebSocket\Tests\Support\RecordingHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Session::class)]
#[CoversClass(ServerContext::class)]
final class SessionTest extends TestCase
{
    private const string ACCEPTED = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . Client::ACCEPT . "\r\n\r\n";

    private ConnectionPool $pool;

    protected function setUp(): void
    {
        $this->pool = new ConnectionPool();
    }

    // ── Opening handshake ────────────────────────────────────────────────

    #[Test]
    public function anAcceptedHandshakeOpensAPooledConnection(): void
    {
        $handler = new RecordingHandler();
        $session = $this->session($handler);

        $session->receive(Client::handshake());

        self::assertSame(self::ACCEPTED, $session->pending());
        $connection = $session->connection();
        self::assertInstanceOf(WebSocketConnection::class, $connection);
        self::assertSame('203.0.113.9:51234', $connection->remoteAddress());
        self::assertSame('/chat', $connection->path());
        self::assertSame(16, strlen($connection->id()));
        self::assertSame($connection, $this->pool->get($connection->id()));
        self::assertSame(['open'], $handler->events);
        self::assertFalse($session->isDone());
    }

    #[Test]
    public function theHandshakeMayArriveInPieces(): void
    {
        $session = $this->session(new RecordingHandler());
        $head = Client::handshake();

        $session->receive(substr($head, 0, 20));
        self::assertSame('', $session->pending());
        self::assertNull($session->connection());

        $session->receive(substr($head, 20));
        self::assertSame(self::ACCEPTED, $session->pending());
    }

    #[Test]
    public function aRefusedHandshakeGetsAFixedResponseAndNoConnection(): void
    {
        $handler = new RecordingHandler();
        $session = $this->session($handler, new HandshakeNegotiator(allowedOrigins: ['https://other.example']));

        $session->receive(Client::handshake());
        self::assertSame(HandshakeNegotiator::rejection(403), $session->pending());
        $session->written(10);
        $session->receive(Client::text('ignored'));

        self::assertSame(substr(HandshakeNegotiator::rejection(403), 10), $session->pending(), 'later bytes change nothing');
        self::assertNull($session->connection());
        self::assertFalse($session->isDone(), 'the rejection still has to be written');
        $session->written(strlen($session->pending()));
        self::assertTrue($session->isDone());
        $session->end();
        self::assertSame([], $handler->events, 'no connection, no events');
        self::assertCount(0, $this->pool);
    }

    #[Test]
    public function framesSentRightAfterTheHandshakeAreKept(): void
    {
        $handler = new RecordingHandler();
        $session = $this->session($handler);

        $session->receive(Client::handshake() . Client::text('first'));

        self::assertSame(['open', 'message'], $handler->events);
        self::assertSame('first', $handler->messages[0]->payload);
    }

    // ── Messages and control frames ──────────────────────────────────────

    #[Test]
    public function fragmentsWithAPingBetweenThemBecomeOneMessage(): void
    {
        $handler = new RecordingHandler();
        $session = $this->opened($handler);

        $session->receive(Client::text('Hel', fin: false) . Client::frame(0x9, 'are you there') . Client::frame(0x0, 'lo'));

        self::assertSame('Hello', $handler->messages[0]->payload ?? null);
        self::assertSame("\x8A\x0Dare you there", $session->pending(), 'the ping got its pong');
    }

    #[Test]
    public function aFrameMayArriveInPiecesAndPongsAreIgnored(): void
    {
        $handler = new RecordingHandler();
        $session = $this->opened($handler);
        $bytes = Client::frame(0xA, 'unsolicited') . Client::frame(0x2, "\x00\x01");

        foreach (str_split($bytes, 3) as $piece) {
            $session->receive($piece);
        }

        self::assertCount(1, $handler->messages);
        self::assertTrue($handler->messages[0]->isBinary());
        self::assertSame('', $session->pending());
    }

    #[Test]
    public function theHandlerCanAnswerThroughTheConnection(): void
    {
        $session = $this->opened(new RecordingHandler(onMessage: static function (Connection $connection, Message $message): void {
            $connection->send(Message::text(strtoupper($message->payload)));
        }));

        $session->receive(Client::text('echo'));

        self::assertSame("\x81\x04ECHO", $session->pending());
    }

    // ── Closing handshake ────────────────────────────────────────────────

    #[Test]
    public function theClientsCloseIsEchoedAndTheServerClosesFirst(): void
    {
        $handler = new RecordingHandler();
        $session = $this->opened($handler);

        $session->receive(Client::close(1001, 'tab closed') . Client::text('after close'));

        self::assertSame("\x88\x02\x03\xE9", $session->pending());
        self::assertSame([[1001, 'tab closed']], $handler->closes);
        self::assertSame([], $handler->messages, 'nothing after the Close is read');
        $session->written(4);
        self::assertTrue($session->isDone());
        $session->end();
        self::assertSame(ConnectionState::Closed, $session->connection()?->state());
        self::assertSame([[1001, 'tab closed']], $handler->closes, 'onClose runs once');
    }

    #[Test]
    public function aCloseWithoutCodeIsEchoedEmptyAndReportedAs1005(): void
    {
        $handler = new RecordingHandler();
        $session = $this->opened($handler);

        $session->receive(Client::close(null));

        self::assertSame("\x88\x00", $session->pending());
        self::assertSame([[1005, '']], $handler->closes);
    }

    #[Test]
    public function theServersCloseWaitsForTheClientsAnswer(): void
    {
        $handler = new RecordingHandler(onMessage: static function (Connection $connection): void {
            $connection->close(CloseCode::PolicyViolation, 'rate limit');
        });
        $session = $this->opened($handler);

        $session->receive(Client::text('spam'));
        self::assertSame("\x88\x0C\x03\xF0rate limit", $session->pending());
        $session->written(14);

        $session->receive(Client::text('more spam') . Client::frame(0x9, 'ping'));
        self::assertSame('', $session->pending(), 'no data and no pong after the server sent Close');
        self::assertCount(1, $handler->messages);

        $session->tick(100.0, 5.0);
        $session->tick(104.9, 5.0);
        self::assertFalse($session->isDone());

        $session->receive(Client::close(1008));
        self::assertSame('', $session->pending(), 'the server already sent its Close');
        self::assertTrue($session->isDone());
        self::assertSame([[1008, '']], $handler->closes);
    }

    #[Test]
    public function aClientThatNeverAnswersIsDroppedAfterTheTimeout(): void
    {
        $handler = new RecordingHandler(onOpen: static function (Connection $connection): void {
            $connection->close();
        });
        $session = $this->opened($handler);
        $session->written(strlen($session->pending()));

        $session->tick(10.0, 5.0);
        $session->tick(14.0, 5.0);
        self::assertFalse($session->isDone());
        $session->tick(15.0, 5.0);
        self::assertTrue($session->isDone());

        $session->end();
        self::assertSame([[1006, '']], $handler->closes);
    }

    #[Test]
    public function aTickOnAnOpenOrFinishedSessionChangesNothing(): void
    {
        $handler = new RecordingHandler();
        $session = $this->opened($handler);

        $session->tick(0.0, 0.0);
        self::assertFalse($session->isDone());

        $session->receive(Client::close());
        $session->tick(1.0, 0.0);
        self::assertSame("\x88\x02\x03\xE8", $session->pending());
    }

    #[Test]
    public function beforeTheHandshakeTickAndShutdownOnlyEndTheSession(): void
    {
        $handler = new RecordingHandler();
        $session = $this->session($handler);
        $session->receive("GET / HTTP/1.1\r\n");

        $session->tick(1.0, 0.0);
        self::assertFalse($session->isDone());

        $session->shutdown(CloseCode::GoingAway);
        self::assertTrue($session->isDone());
        self::assertSame('', $session->pending());
        self::assertSame([], $handler->events);
        self::assertCount(0, $this->pool);
    }

    #[Test]
    public function aDroppedTcpConnectionIsReportedAs1006(): void
    {
        $handler = new RecordingHandler();
        $session = $this->opened($handler);
        $session->receive(Client::text('Hel', fin: false));

        $session->end();
        $session->receive(Client::frame(0x0, 'lo'));

        self::assertSame([[1006, '']], $handler->closes);
        self::assertSame([], $handler->messages);
        self::assertSame('', $session->pending());
        self::assertTrue($session->isDone());
    }

    #[Test]
    public function shutdownSendsGoingAway(): void
    {
        $handler = new RecordingHandler();
        $session = $this->opened($handler);

        $session->shutdown(CloseCode::GoingAway);

        self::assertSame("\x88\x0C\x03\xE9Going Away", $session->pending());
        self::assertSame([[1001, 'Going Away']], $handler->closes);
        $session->written(14);
        self::assertTrue($session->isDone());
    }

    // ── Failures ─────────────────────────────────────────────────────────

    #[Test]
    public function aProtocolErrorClosesWithItsCode(): void
    {
        $cases = [
            [Client::frame(0x1, 'x', masked: false), WsCode::UnmaskedClientFrame, "\x88\x10\x03\xEAProtocol Error", 1002],
            [Client::text("\xFF"), WsCode::InvalidUtf8, "\x88\x16\x03\xEFInvalid Payload Data", 1007],
            [Client::frame(0x2, str_repeat('x', 101)), WsCode::FrameTooLarge, "\x88\x11\x03\xF1Message Too Big", 1009],
            [Client::frame(0x0, 'orphan'), WsCode::UnexpectedContinuation, "\x88\x10\x03\xEAProtocol Error", 1002],
            [Client::frame(0x8, "\x03"), WsCode::InvalidClosePayload, "\x88\x10\x03\xEAProtocol Error", 1002],
        ];

        foreach ($cases as [$bytes, $code, $close, $reported]) {
            $handler = new RecordingHandler();
            $session = $this->opened($handler, new ServerOptions(maxPayloadSize: 100));

            $session->receive($bytes . Client::text('never read'));

            self::assertSame($close, $session->pending());
            self::assertSame(['open', 'error', 'close'], $handler->events);
            self::assertInstanceOf(FrameException::class, $handler->errors[0]);
            self::assertSame($code, $handler->errors[0]->errorCode);
            self::assertSame($reported, $handler->closes[0][0]);
            $session->written(strlen($close));
            self::assertTrue($session->isDone());
        }
    }

    #[Test]
    public function anExceptionFromTheHandlerClosesWith1011(): void
    {
        $failure = new \RuntimeException('database down');
        $handler = new RecordingHandler(onMessage: static function () use ($failure): void {
            throw $failure;
        });
        $session = $this->opened($handler);

        $session->receive(Client::text('query'));

        self::assertSame([$failure], $handler->errors);
        self::assertSame("\x88\x10\x03\xF3Internal Error", $session->pending());
        self::assertSame(ConnectionState::Closing, $session->connection()?->state());
        self::assertSame([], $handler->closes, 'the closing handshake runs as usual');
    }

    #[Test]
    public function anExceptionFromOnOpenClosesWith1011(): void
    {
        $handler = new RecordingHandler(onOpen: static function (): void {
            throw new \LogicException('no seat left');
        });
        $session = $this->session($handler);

        $session->receive(Client::handshake());

        self::assertSame(self::ACCEPTED . "\x88\x10\x03\xF3Internal Error", $session->pending());
        self::assertCount(1, $handler->errors);
    }

    #[Test]
    public function exceptionsFromOnErrorAndOnCloseDoNotEscape(): void
    {
        $handler = new RecordingHandler(failOnError: true, failOnClose: true);
        $session = $this->opened($handler);

        $session->receive(Client::frame(0x3));

        self::assertSame(['open', 'error', 'close', 'error'], $handler->events);
        self::assertInstanceOf(\LogicException::class, $handler->errors[1]);
    }

    #[Test]
    public function writtenRemovesOnlyWhatTheSocketTook(): void
    {
        $session = $this->session(new RecordingHandler());
        $session->receive(Client::handshake());

        $session->written(9);

        self::assertSame(substr(self::ACCEPTED, 9), $session->pending());
    }

    private function session(RecordingHandler $handler, HandshakeNegotiator $negotiator = new HandshakeNegotiator(), ServerOptions $options = new ServerOptions()): Session
    {
        return new Session('203.0.113.9:51234', new ServerContext($handler, $negotiator, $this->pool, $options));
    }

    private function opened(RecordingHandler $handler, ServerOptions $options = new ServerOptions()): Session
    {
        $session = $this->session($handler, options: $options);
        $session->receive(Client::handshake());
        $output = $session->pending();
        $session->written(strlen(self::ACCEPTED));
        self::assertStringStartsWith(self::ACCEPTED, $output);

        return $session;
    }
}
