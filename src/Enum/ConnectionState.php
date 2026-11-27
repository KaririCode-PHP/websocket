<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Enum;

/**
 * The life of a WebSocket connection after the opening handshake (RFC 6455
 * §4.1, §7): Open carries data both ways; Closing means a Close frame was
 * sent and the server waits for the client's; Closed means the TCP
 * connection is gone. A connection object exists only once the handshake
 * succeeded, so the CONNECTING state of the RFC belongs to the server.
 *
 * ARFA 1.3: P1 (immutable cases). Spec V4.3.3: pure enum; the names are
 * not a wire contract.
 *
 * Preconditions: none.
 *
 * Thread-safety: enum cases are immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     if ($connection->state()->canSend()) {
 *         $connection->send(Message::text('hello'));
 *     }
 *
 * References: RFC 6455 §4.1 (readyState), §7.1.1–§7.1.4.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-7
 */
enum ConnectionState
{
    case Open;
    case Closing;
    case Closed;

    public function canSend(): bool
    {
        return $this === self::Open;
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }
}
