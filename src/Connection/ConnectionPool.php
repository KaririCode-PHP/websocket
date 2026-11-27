<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Connection;

use KaririCode\WebSocket\Contract\Connection;
use KaririCode\WebSocket\Message\Message;

/**
 * In-memory connection registry with O(1) add/remove and O(n) broadcast.
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): the pool enables the
 * broadcast stage of the server's write path — handlers produce
 * messages, the pool fans out to matching connections.
 *
 * Spec V4.0: final class, implements \Countable.
 *
 * Connections are keyed by their unique ID (RFC 6455 does not define
 * connection identifiers — these are server-assigned UUIDs).
 * The pool does NOT own connection lifecycle — callers are responsible
 * for closing connections removed from the pool.
 *
 * Broadcast silently skips connections that fail during send().
 * This avoids a single broken socket from aborting a fan-out to
 * hundreds of healthy connections.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455 WebSocket Protocol
 */
final class ConnectionPool implements \Countable
{
    /** @var array<string, Connection> */
    private array $connections = [];

    public function add(Connection $connection): void
    {
        $this->connections[$connection->id()] = $connection;
    }

    public function remove(string $connectionId): ?Connection
    {
        $connection = $this->connections[$connectionId] ?? null;
        unset($this->connections[$connectionId]);

        return $connection;
    }

    public function get(string $connectionId): ?Connection
    {
        return $this->connections[$connectionId] ?? null;
    }

    public function has(string $connectionId): bool
    {
        return isset($this->connections[$connectionId]);
    }

    /**
     * Sends a message to all connections, optionally filtered.
     *
     * @param callable(Connection): bool|null $filter Return true to include
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455 WebSocket Protocol
     */
    public function broadcast(Message $message, ?callable $filter = null): int
    {
        $sent = 0;

        foreach ($this->connections as $connection) {
            if ($filter !== null && !$filter($connection)) {
                continue;
            }

            if (!$connection->state()->canSendData()) {
                continue;
            }

            try {
                $connection->send($message);
                $sent++;
            } catch (\Throwable) {
                // Skip failed connections — caller handles cleanup
            }
        }

        return $sent;
    }

    /**
     * @return array<string, Connection>
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455 WebSocket Protocol
     */
    public function all(): array
    {
        return $this->connections;
    }

    public function count(): int
    {
        return count($this->connections);
    }

    public function clear(): void
    {
        $this->connections = [];
    }
}

