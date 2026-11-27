<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Connection;

use KaririCode\WebSocket\Connection\WebSocketConnection;
use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\ConnectionState;
use KaririCode\WebSocket\Exception\ConnectionException;
use KaririCode\WebSocket\Exception\InvalidMessageException;
use KaririCode\WebSocket\Exception\WsCode;
use KaririCode\WebSocket\Handshake\HandshakeRequest;
use KaririCode\WebSocket\Message\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketConnection::class)]
#[CoversClass(ConnectionState::class)]
final class WebSocketConnectionTest extends TestCase
{
    #[Test]
    public function itReportsWhatTheHandshakeEstablished(): void
    {
        $request = new HandshakeRequest('/chat/7?token=x', ['host' => ['a.example']]);
        $connection = new WebSocketConnection('c1', '203.0.113.9:51234', $request, 'chat.v2');

        self::assertSame('c1', $connection->id());
        self::assertSame('203.0.113.9:51234', $connection->remoteAddress());
        self::assertSame($request, $connection->request());
        self::assertSame('/chat/7', $connection->path());
        self::assertSame('chat.v2', $connection->subprotocol());
        self::assertSame(ConnectionState::Open, $connection->state());
        self::assertNull(self::connection()->subprotocol());
    }

    #[Test]
    public function messagesAndPingsAreQueuedAsServerFrames(): void
    {
        $connection = self::connection();

        $connection->send(Message::text('hi'));
        $connection->send(Message::binary("\x00"));
        $connection->ping('p');

        self::assertSame("\x81\x02hi\x82\x01\x00\x89\x01p", $connection->takeOutput());
        self::assertSame('', $connection->takeOutput(), 'taking empties the outbox');
    }

    #[Test]
    public function closeQueuesOneCloseFrameAndStopsTheRest(): void
    {
        $connection = self::connection();

        $connection->close(CloseCode::GoingAway, 'bye');
        $connection->close(CloseCode::NormalClosure);
        $connection->pong('late');
        $connection->respondToClose(1000);

        self::assertSame(ConnectionState::Closing, $connection->state());
        self::assertFalse($connection->state()->canSend());
        self::assertSame("\x88\x05\x03\xE9bye", $connection->takeOutput());

        foreach ([static fn() => $connection->send(Message::text('x')), static fn() => $connection->ping()] as $operation) {
            try {
                $operation();
                self::fail('a closing connection sent data');
            } catch (ConnectionException $e) {
                self::assertSame(WsCode::NotOpen, $e->errorCode);
            }
        }
    }

    #[Test]
    public function controlFramesQueueBehindWhatIsAlreadyWaiting(): void
    {
        $closing = self::connection();
        $answering = self::connection();

        $closing->send(Message::text('a'));
        $closing->close();
        $answering->send(Message::text('b'));
        $answering->pong('p');
        $answering->respondToClose(1000);

        self::assertSame("\x81\x01a\x88\x02\x03\xE8", $closing->takeOutput());
        self::assertSame("\x81\x01b\x8A\x01p\x88\x02\x03\xE8", $answering->takeOutput());
    }

    #[Test]
    public function anInvalidCloseIsRefusedEvenWhenClosing(): void
    {
        $connection = self::connection();
        $connection->close();

        try {
            $connection->close(1005);
            self::fail('1005 was accepted');
        } catch (InvalidMessageException $e) {
            self::assertSame(WsCode::UnsendableCloseCode, $e->errorCode);
        }

        self::assertSame("\x88\x02\x03\xE8", $connection->takeOutput());
    }

    #[Test]
    public function aPingIsAnsweredWithTheSamePayload(): void
    {
        $connection = self::connection();

        $connection->pong('abc');

        self::assertSame("\x8A\x03abc", $connection->takeOutput());
    }

    #[Test]
    public function theClientsCloseIsEchoedWithItsCodeOrEmpty(): void
    {
        $withCode = self::connection();
        $withoutCode = self::connection();

        $withCode->respondToClose(4001);
        $withoutCode->respondToClose(null);

        self::assertSame("\x88\x02\x0F\xA1", $withCode->takeOutput());
        self::assertSame("\x88\x00", $withoutCode->takeOutput());
        self::assertSame(ConnectionState::Closing, $withCode->state());
    }

    #[Test]
    public function markClosedEndsTheConnection(): void
    {
        $connection = self::connection();

        $connection->markClosed();

        self::assertSame(ConnectionState::Closed, $connection->state());
        self::assertTrue($connection->state()->isClosed());
        self::assertFalse(ConnectionState::Closing->isClosed());
        self::assertTrue(ConnectionState::Open->canSend());
        $connection->close();
        self::assertSame('', $connection->takeOutput(), 'nothing is queued on a closed connection');
    }

    #[Test]
    public function attributesHoldWhatTheApplicationAttaches(): void
    {
        $connection = self::connection();

        $connection->putAttribute('user', 42);
        $connection->putAttribute('nothing', null);

        self::assertSame(42, $connection->attribute('user'));
        self::assertNull($connection->attribute('nothing', 'default'), 'a stored null is not the default');
        self::assertSame('default', $connection->attribute('absent', 'default'));
        self::assertSame(['user' => 42, 'nothing' => null], $connection->attributes());
    }

    private static function connection(): WebSocketConnection
    {
        return new WebSocketConnection('id', '127.0.0.1:1', new HandshakeRequest('/', []));
    }
}
