<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Exception;

use KaririCode\Exception\RuntimeException;

/**
 * A connection or the server socket cannot do what was asked right now:
 * data or a ping for a connection that is closing or closed, or a listen
 * address the operating system refused. The same call can succeed on
 * another connection or after the address is free.
 *
 * Preconditions: none.
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $connection->send(Message::text('update'));
 *     } catch (ConnectionException) {
 *         // the client left between the check and the send
 *     }
 *
 * References: RFC 6455 §7.1.1–§7.1.4 (closing); PHP manual,
 * stream_socket_server(); ADR-003.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-7.1
 */
final class ConnectionException extends RuntimeException
{
    public static function notOpen(): self
    {
        return new self(WsCode::NotOpen, 'The connection is closing or closed; nothing more can be sent.');
    }

    public static function bindFailed(string $address): self
    {
        return new self(WsCode::BindFailed, sprintf('The server could not listen on %s.', self::quote($address)));
    }
}
