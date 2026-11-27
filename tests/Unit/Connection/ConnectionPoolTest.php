<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Connection;

use KaririCode\WebSocket\Connection\ConnectionPool;
use KaririCode\WebSocket\Connection\WebSocketConnection;
use KaririCode\WebSocket\Contract\Connection;
use KaririCode\WebSocket\Enum\ConnectionState;
use KaririCode\WebSocket\Exception\ConnectionException;
use KaririCode\WebSocket\Handshake\HandshakeRequest;
use KaririCode\WebSocket\Message\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionPool::class)]
final class ConnectionPoolTest extends TestCase
{
    #[Test]
    public function connectionsAreKeptById(): void
    {
        $pool = new ConnectionPool();
        $a = self::connection('a');
        $b = self::connection('b');

        $pool->add($a);
        $pool->add($b);

        self::assertCount(2, $pool);
        self::assertSame($a, $pool->get('a'));
        self::assertTrue($pool->has('b'));
        self::assertNull($pool->get('missing'));
        self::assertFalse($pool->has('missing'));
        self::assertSame(['a' => $a, 'b' => $b], $pool->all());
        self::assertSame($a, $pool->remove('a'));
        self::assertNull($pool->remove('a'));
        self::assertSame(['b' => $b], $pool->all());

        $pool->clear();
        self::assertSame(0, $pool->count());
    }

    #[Test]
    public function aBroadcastReachesOpenConnectionsTheFilterAccepts(): void
    {
        $pool = new ConnectionPool();
        $lobby = self::connection('lobby');
        $ops = self::connection('ops');
        $leaving = self::connection('leaving');
        $ops->putAttribute('room', 'ops');
        $leaving->close();
        $leaving->takeOutput();

        foreach ([$lobby, $ops, $leaving] as $connection) {
            $pool->add($connection);
        }

        self::assertSame(2, $pool->broadcast(Message::text('all')));
        self::assertSame(1, $pool->broadcast(Message::text('ops'), static fn(Connection $c): bool => $c->attribute('room') === 'ops'));
        self::assertSame("\x81\x03all", $lobby->takeOutput());
        self::assertSame("\x81\x03all\x81\x03ops", $ops->takeOutput());
        self::assertSame('', $leaving->takeOutput());
    }

    #[Test]
    public function aConnectionThatFailsToSendDoesNotStopTheBroadcast(): void
    {
        $failing = $this->createStub(Connection::class);
        $failing->method('id')->willReturn('failing');
        $failing->method('state')->willReturn(ConnectionState::Open);
        $failing->method('send')->willThrowException(ConnectionException::notOpen());
        $healthy = self::connection('healthy');
        $pool = new ConnectionPool();
        $pool->add($failing);
        $pool->add($healthy);

        self::assertSame(1, $pool->broadcast(Message::text('x')));
        self::assertSame("\x81\x01x", $healthy->takeOutput());
    }

    private static function connection(string $id): WebSocketConnection
    {
        return new WebSocketConnection($id, '127.0.0.1:1', new HandshakeRequest('/', []));
    }
}
