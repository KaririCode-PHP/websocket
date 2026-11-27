<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Contract;

use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\ConnectionState;
use KaririCode\WebSocket\Handshake\HandshakeRequest;
use KaririCode\WebSocket\Message\Message;

/**
 * One WebSocket connection as the application sees it, from the accepted
 * handshake to the close: its identity, the handshake request, the
 * subprotocol, sending messages and pings, closing, and attributes the
 * application attaches (the authenticated user, a room).
 *
 * ARFA 1.3: P4 (the application does not see sockets). Spec V4.3.3:
 * interface; the server's implementation is WebSocketConnection.
 *
 * Preconditions: send() and ping() need an open connection
 * (ConnectionException 1850); close() takes a code a peer may send and a
 * reason of ≤ 123 bytes of UTF-8 (InvalidMessageException 1841–1843).
 *
 * Thread-safety: implementations hold the state of one connection; use
 * them from the server's event loop.
 *
 * Side effects: send(), ping() and close() queue frames for the server to
 * write.
 *
 * Example:
 *
 *     public function onOpen(Connection $connection): void
 *     {
 *         $connection->putAttribute('room', $connection->request()->path());
 *         $connection->send(Message::text('welcome'));
 *     }
 *
 * References: RFC 6455 §1.4, §5.5.2, §7.1.2; ADR-002.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-7.1.2
 */
interface Connection
{
    public function id(): string;

    public function state(): ConnectionState;

    /** The peer address as the socket reports it ("203.0.113.9:51234"). */
    public function remoteAddress(): string;

    public function request(): HandshakeRequest;

    /** The request path the client opened, for routing. */
    public function path(): string;

    /** The subprotocol the handshake selected, or null. */
    public function subprotocol(): ?string;

    public function send(Message $message): void;

    public function ping(string $payload = ''): void;

    /** Starts the closing handshake; a connection already closing or closed ignores it. */
    public function close(CloseCode|int $code = CloseCode::NormalClosure, string $reason = ''): void;

    public function attribute(string $name, mixed $default = null): mixed;

    public function putAttribute(string $name, mixed $value): void;

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array;
}
