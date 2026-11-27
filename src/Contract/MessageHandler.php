<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Contract;

use KaririCode\WebSocket\Message\Message;

/**
 * What the application does with a connection: onOpen() after the
 * handshake, onMessage() for every whole message, onError() when the
 * client breaks the protocol or a handler throws, and onClose() exactly
 * once at the end, with the close code (1005 when the client sent none,
 * 1006 when the TCP connection dropped without a close) and reason.
 *
 * ARFA 1.3: P2 (the last stage of the read path). Spec V4.3.3: interface.
 *
 * Preconditions: the methods should return quickly: they run inside the
 * server's event loop, which serves every connection.
 *
 * Thread-safety: called from the event loop only.
 *
 * Side effects: those of the application.
 *
 * Example:
 *
 *     final class Echo implements MessageHandler
 *     {
 *         public function onOpen(Connection $connection): void {}
 *         public function onMessage(Connection $connection, Message $message): void { $connection->send($message); }
 *         public function onClose(Connection $connection, int $code, string $reason): void {}
 *         public function onError(Connection $connection, \Throwable $error): void {}
 *     }
 *
 * References: RFC 6455 §7.1.5, §7.1.6, §7.4.1; ADR-002.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-7.1.5
 */
interface MessageHandler
{
    public function onOpen(Connection $connection): void;

    public function onMessage(Connection $connection, Message $message): void;

    public function onClose(Connection $connection, int $code, string $reason): void;

    public function onError(Connection $connection, \Throwable $error): void;
}
