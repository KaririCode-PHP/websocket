<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Exception;

use KaririCode\WebSocket\Enum\CloseCode;

/**
 * Thrown when a received frame violates RFC 6455 §5 framing rules.
 *
 * ARFA 1.3 P1: each violation maps to a specific immutable
 * {@see CloseCode} via static factories. The attached close code
 * determines the close frame sent before the connection is failed.
 *
 * Spec V4.0: final class with static factories for each violation type.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5.2 Base Framing Protocol
 */
final class FrameException extends WebSocketException
{
    public static function reservedOpcode(int $opcode): self
    {
        return new self(CloseCode::ProtocolError, "Reserved opcode: 0x" . dechex($opcode));
    }

    public static function controlFrameTooLarge(int $length): self
    {
        return new self(
            CloseCode::ProtocolError,
            "Control frame payload exceeds 125 bytes: {$length}",
        );
    }

    public static function fragmentedControlFrame(): self
    {
        return new self(CloseCode::ProtocolError, 'Control frame MUST NOT be fragmented');
    }

    public static function payloadTooLarge(int $length, int $maxLength): self
    {
        return new self(
            CloseCode::MessageTooBig,
            "Payload {$length} bytes exceeds limit {$maxLength}",
        );
    }

    public static function invalidUtf8(): self
    {
        return new self(CloseCode::InvalidPayload, 'Text frame contains invalid UTF-8');
    }

    public static function maskRequired(): self
    {
        return new self(CloseCode::ProtocolError, 'Client frames MUST be masked (RFC 6455 §5.1)');
    }
}

