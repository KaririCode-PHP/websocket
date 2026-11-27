<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Connection;

use KaririCode\WebSocket\Contract\Connection;
use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\ConnectionState;
use KaririCode\WebSocket\Connection\ConnectionPool;
use KaririCode\WebSocket\Message\Message;
use PHPUnit\Framework\TestCase;

final class ConnectionPoolTest extends TestCase
{
    private ConnectionPool $pool;

    protected function setUp(): void
    {
        $this->pool = new ConnectionPool();
    }

    public function testAddAndGet(): void
    {
        $conn = $this->createConnection('conn-1');
        $this->pool->add($conn);

        self::assertSame($conn, $this->pool->get('conn-1'));
        self::assertTrue($this->pool->has('conn-1'));
        self::assertCount(1, $this->pool);
    }

    public function testRemove(): void
    {
        $conn = $this->createConnection('conn-1');
        $this->pool->add($conn);

        $removed = $this->pool->remove('conn-1');

        self::assertSame($conn, $removed);
        self::assertFalse($this->pool->has('conn-1'));
        self::assertNull($this->pool->get('conn-1'));
        self::assertCount(0, $this->pool);
    }

    public function testRemoveNonExistent(): void
    {
        self::assertNull($this->pool->remove('nonexistent'));
    }

    public function testGetNonExistent(): void
    {
        self::assertNull($this->pool->get('nonexistent'));
    }

    public function testAll(): void
    {
        $conn1 = $this->createConnection('a');
        $conn2 = $this->createConnection('b');
        $this->pool->add($conn1);
        $this->pool->add($conn2);

        $all = $this->pool->all();
        self::assertCount(2, $all);
        self::assertArrayHasKey('a', $all);
        self::assertArrayHasKey('b', $all);
    }

    public function testClear(): void
    {
        $this->pool->add($this->createConnection('a'));
        $this->pool->add($this->createConnection('b'));
        self::assertCount(2, $this->pool);

        $this->pool->clear();
        self::assertCount(0, $this->pool);
    }

    public function testBroadcast(): void
    {
        $messages = [];
        $conn1 = $this->createConnection('a', function (Message $msg) use (&$messages) {
            $messages[] = 'a:' . $msg->payload;
        });
        $conn2 = $this->createConnection('b', function (Message $msg) use (&$messages) {
            $messages[] = 'b:' . $msg->payload;
        });

        $this->pool->add($conn1);
        $this->pool->add($conn2);

        $sent = $this->pool->broadcast(Message::text('hello'));

        self::assertSame(2, $sent);
        self::assertContains('a:hello', $messages);
        self::assertContains('b:hello', $messages);
    }

    public function testBroadcastWithFilter(): void
    {
        $messages = [];
        $conn1 = $this->createConnection('a', function (Message $msg) use (&$messages) {
            $messages[] = 'a';
        }, ['room' => 'lobby']);
        $conn2 = $this->createConnection('b', function (Message $msg) use (&$messages) {
            $messages[] = 'b';
        }, ['room' => 'vip']);

        $this->pool->add($conn1);
        $this->pool->add($conn2);

        $sent = $this->pool->broadcast(
            Message::text('lobby-only'),
            fn (Connection $c) => ($c->metadata()['room'] ?? '') === 'lobby',
        );

        self::assertSame(1, $sent);
        self::assertContains('a', $messages);
        self::assertNotContains('b', $messages);
    }

    public function testBroadcastSkipsClosedConnections(): void
    {
        $conn = $this->createConnection('a', state: ConnectionState::Closed);
        $this->pool->add($conn);

        $sent = $this->pool->broadcast(Message::text('nope'));
        self::assertSame(0, $sent);
    }

    public function testBroadcastHandlesSendFailure(): void
    {
        $conn = $this->createConnection('a', function () {
            throw new \RuntimeException('write failed');
        });
        $this->pool->add($conn);

        // Should not throw — failures are silently skipped
        $sent = $this->pool->broadcast(Message::text('test'));
        self::assertSame(0, $sent);
    }

    public function testCountable(): void
    {
        self::assertSame(0, count($this->pool));

        $this->pool->add($this->createConnection('a'));
        self::assertSame(1, count($this->pool));

        $this->pool->add($this->createConnection('b'));
        self::assertSame(2, count($this->pool));
    }

    public function testOverwriteExistingConnection(): void
    {
        $conn1 = $this->createConnection('a');
        $conn2 = $this->createConnection('a');

        $this->pool->add($conn1);
        $this->pool->add($conn2);

        self::assertSame($conn2, $this->pool->get('a'));
        self::assertCount(1, $this->pool);
    }

    // ── Helper ───────────────────────────────────────────────────────────

    private function createConnection(
        string $id,
        ?\Closure $sendCallback = null,
        array $metadata = [],
        ConnectionState $state = ConnectionState::Open,
    ): Connection {
        return new class ($id, $sendCallback, $metadata, $state) implements Connection {
            public function __construct(
                private readonly string $connectionId,
                private readonly ?\Closure $sendCallback,
                private readonly array $meta,
                private readonly ConnectionState $connectionState,
            ) {
            }

            public function id(): string { return $this->connectionId; }
            public function state(): ConnectionState { return $this->connectionState; }
            public function remoteAddress(): string { return '127.0.0.1'; }
            public function path(): string { return '/'; }

            public function send(Message $message): void
            {
                if ($this->sendCallback !== null) {
                    ($this->sendCallback)($message);
                }
            }

            public function close(CloseCode $code = CloseCode::NormalClosure, string $reason = ''): void {}
            public function metadata(): array { return $this->meta; }
            public function setMetadata(string $key, mixed $value): void {}
        };
    }
}
