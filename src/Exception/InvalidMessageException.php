<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Exception;

use KaririCode\Exception\ValidationException;

/**
 * The application asked to send something RFC 6455 does not allow: a text
 * message that is not UTF-8, a Close reason longer than 123 bytes or not
 * UTF-8, a close code a peer may not send (1005, 1006, 1015, or outside
 * 1000–1014 and 3000–4999), a control payload above 125 bytes, a
 * fragmented control frame, or a control frame handed to the message
 * assembler.
 *
 * Preconditions: messages name the rule, never the payload.
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $connection->send(Message::text($bytes));
 *     } catch (InvalidMessageException) {
 *         $connection->send(Message::binary($bytes));
 *     }
 *
 * References: RFC 6455 §5.5, §5.5.1, §5.6, §7.4.1, §7.4.2; ADR-003.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-5.5
 */
final class InvalidMessageException extends ValidationException
{
    public static function textNotUtf8(): self
    {
        return new self(WsCode::TextNotUtf8, 'A text message must be valid UTF-8; send bytes as a binary message.');
    }

    public static function closeReasonTooLong(): self
    {
        return new self(WsCode::CloseReasonTooLong, 'A close reason holds at most 123 bytes.');
    }

    public static function closeReasonNotUtf8(): self
    {
        return new self(WsCode::CloseReasonNotUtf8, 'A close reason must be valid UTF-8.');
    }

    public static function unsendableCloseCode(int $code): self
    {
        return new self(WsCode::UnsendableCloseCode, sprintf('Close code %d may not be sent; use 1000-1003, 1007-1014 or 3000-4999.', $code));
    }

    public static function controlPayloadTooLarge(): self
    {
        return new self(WsCode::ControlPayloadTooLarge, 'A control frame carries at most 125 bytes and cannot be fragmented.');
    }

    public static function controlOpcodeInMessage(): self
    {
        return new self(WsCode::ControlOpcodeInMessage, 'Close, Ping and Pong frames are not part of a message.');
    }
}
