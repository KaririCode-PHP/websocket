<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Exception;

use KaririCode\Exception\ProtocolException;
use KaririCode\WebSocket\Enum\CloseCode;

/**
 * A client broke the framing rules of RFC 6455: a reserved opcode or bit,
 * an unmasked frame, a control frame that is long or fragmented, a length
 * that is not minimal or out of range, a frame or message above the
 * configured limit, a continuation without a message or a new message
 * inside one, text that is not UTF-8, or a Close frame whose payload,
 * code or reason is invalid. Each carries the close code RFC 6455 §7.4.1
 * asks the server to send: 1002 for protocol errors, 1007 for invalid
 * UTF-8, 1009 for size limits.
 *
 * Preconditions: messages name the rule, never the bytes the client sent
 * (errata E-047).
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $result = $parser->parse($buffer);
 *     } catch (FrameException $e) {
 *         $connection->close($e->closeCode);  // fail the connection (RFC 6455 §7.1.7)
 *     }
 *
 * References: RFC 6455 §5.2, §5.4, §5.5, §5.6, §7.1.7, §7.4.1, §8.1;
 * KaririCode Spec V4.3.3 §8.4; ADR-003.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-7.4.1
 */
final class FrameException extends ProtocolException
{
    private function __construct(WsCode $code, public readonly CloseCode $closeCode, string $message)
    {
        parent::__construct($code, $message);
    }

    public static function reservedOpcode(): self
    {
        return new self(WsCode::ReservedOpcode, CloseCode::ProtocolError, 'The frame uses a reserved opcode.');
    }

    public static function reservedBits(): self
    {
        return new self(WsCode::ReservedBits, CloseCode::ProtocolError, 'The frame sets RSV bits, and no extension was negotiated.');
    }

    public static function unmaskedClientFrame(): self
    {
        return new self(WsCode::UnmaskedClientFrame, CloseCode::ProtocolError, 'A client frame must be masked.');
    }

    public static function controlFrameTooLarge(): self
    {
        return new self(WsCode::ControlFrameTooLarge, CloseCode::ProtocolError, 'A control frame carries at most 125 bytes.');
    }

    public static function fragmentedControlFrame(): self
    {
        return new self(WsCode::FragmentedControlFrame, CloseCode::ProtocolError, 'A control frame must not be fragmented.');
    }

    public static function frameTooLarge(): self
    {
        return new self(WsCode::FrameTooLarge, CloseCode::MessageTooBig, 'The frame payload exceeds the configured limit.');
    }

    public static function nonMinimalLength(): self
    {
        return new self(WsCode::NonMinimalLength, CloseCode::ProtocolError, 'The payload length must use the shortest encoding.');
    }

    public static function lengthOutOfRange(): self
    {
        return new self(WsCode::LengthOutOfRange, CloseCode::ProtocolError, 'The most significant bit of a 64-bit payload length must be 0.');
    }

    public static function messageTooLarge(): self
    {
        return new self(WsCode::MessageTooLarge, CloseCode::MessageTooBig, 'The message exceeds the configured limit.');
    }

    public static function unexpectedContinuation(): self
    {
        return new self(WsCode::UnexpectedContinuation, CloseCode::ProtocolError, 'A continuation frame arrived without a message to continue.');
    }

    public static function interleavedDataFrame(): self
    {
        return new self(WsCode::InterleavedDataFrame, CloseCode::ProtocolError, 'A new data frame arrived before the fragmented message ended.');
    }

    public static function invalidUtf8(): self
    {
        return new self(WsCode::InvalidUtf8, CloseCode::InvalidPayload, 'A text message must be valid UTF-8.');
    }

    public static function invalidClosePayload(): self
    {
        return new self(WsCode::InvalidClosePayload, CloseCode::ProtocolError, 'A Close payload is empty or starts with a 2-byte status code.');
    }

    public static function invalidCloseCode(): self
    {
        return new self(WsCode::InvalidCloseCode, CloseCode::ProtocolError, 'The Close frame carries a status code a peer may not send.');
    }

    public static function invalidCloseReason(): self
    {
        return new self(WsCode::InvalidCloseReason, CloseCode::InvalidPayload, 'The reason of a Close frame must be valid UTF-8.');
    }
}
