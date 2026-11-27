<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Connection;

use KaririCode\WebSocket\Contract\Connection;
use KaririCode\WebSocket\Exception\ConnectionException;
use KaririCode\WebSocket\Message\Message;

/**
 * The open connections of one server, by id: the server adds a connection
 * when its handshake succeeds and removes it when the TCP connection ends,
 * and the application looks connections up or broadcasts to them. A
 * broadcast skips connections that are closing, so one leaving client
 * does not stop the others from getting the message.
 *
 * ARFA 1.3: P2 (fan-out). Spec V4.3.3: final, a registry of one process.
 *
 * Preconditions: ids are unique (the server generates them).
 *
 * Thread-safety: not shared across processes; use it from the event loop.
 *
 * Side effects: broadcast() queues frames on the connections.
 *
 * Example:
 *
 *     $server->connections()->broadcast(
 *         Message::text('{"event":"deploy"}'),
 *         static fn(Connection $c): bool => $c->attribute('room') === '/ops',
 *     );
 *
 * References: RFC 6455 §7.1.2 (no data after Close); ADR-002.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-7.1.2
 */
final class ConnectionPool implements \Countable
{
    /** @var array<string, Connection> */
    private array $connections = [];

    public function add(Connection $connection): void
    {
        $this->connections[$connection->id()] = $connection;
    }

    public function remove(string $id): ?Connection
    {
        $connection = $this->connections[$id] ?? null;
        unset($this->connections[$id]);

        return $connection;
    }

    public function get(string $id): ?Connection
    {
        return $this->connections[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->connections[$id]);
    }

    /**
     * Sends $message to every open connection $filter accepts.
     *
     * @param (\Closure(Connection): bool)|null $filter
     *
     * @return int the number of connections the message was queued for
     */
    public function broadcast(Message $message, ?\Closure $filter = null): int
    {
        $sent = 0;

        foreach ($this->connections as $connection) {
            if (!$connection->state()->canSend() || ($filter !== null && !$filter($connection))) {
                continue;
            }

            try {
                $connection->send($message);
                ++$sent;
            } catch (ConnectionException) {
                // It started closing inside a filter or an earlier send; the others still get the message.
            }
        }

        return $sent;
    }

    /**
     * @return array<string, Connection>
     */
    public function all(): array
    {
        return $this->connections;
    }

    #[\Override]
    public function count(): int
    {
        return count($this->connections);
    }

    public function clear(): void
    {
        $this->connections = [];
    }
}
