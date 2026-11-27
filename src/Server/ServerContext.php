<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Server;

use KaririCode\WebSocket\Connection\ConnectionPool;
use KaririCode\WebSocket\Contract\MessageHandler;
use KaririCode\WebSocket\Handshake\HandshakeNegotiator;

/**
 * What every session of one server shares: the application's handler, the
 * handshake rules, the pool of open connections and the options. The
 * server builds it once and hands it to each session it accepts.
 *
 * ARFA 1.3: P1 (immutable references). Spec V4.3.3: final readonly Value
 * Object; the pool it refers to is the server's mutable registry.
 *
 * Preconditions: none.
 *
 * Thread-safety: the references never change; the pool belongs to the
 * event loop.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $session = new Session('203.0.113.9:51234', new ServerContext($handler, new HandshakeNegotiator(), new ConnectionPool(), new ServerOptions()));
 *
 * References: ADR-004.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-4.2
 */
final readonly class ServerContext
{
    public function __construct(
        public MessageHandler $handler,
        public HandshakeNegotiator $negotiator,
        public ConnectionPool $pool,
        public ServerOptions $options,
    ) {}
}
