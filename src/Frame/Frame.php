<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Frame;

use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\InvalidMessageException;

/**
 * One WebSocket frame (RFC 6455 §5): the FIN bit, the opcode, the payload
 * (unmasked) and whether it arrived masked. FrameParser builds frames from
 * client bytes, FrameWriter turns them into server bytes. A control frame
 * (Close, Ping, Pong) is final and carries at most 125 bytes; the
 * constructor refuses anything else, and close() builds only payloads a
 * peer may send: a valid code and a UTF-8 reason of at most 123 bytes.
 *
 * ARFA 1.3: P1 (immutable). Spec V4.3.3: final readonly Value Object.
 *
 * Preconditions: control frames are final with ≤ 125 bytes
 * (InvalidMessageException 1844); close() takes a code isValid() accepts
 * (1843) and a reason of ≤ 123 bytes of UTF-8 (1841, 1842). Data frames
 * may carry any bytes: a text fragment can end inside a UTF-8 sequence.
 *
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $frame = Frame::close(CloseCode::GoingAway, 'Server restart');
 *     $bytes = (new FrameWriter())->write($frame);
 *
 * References: RFC 6455 §5.2, §5.4, §5.5, §5.5.1, §7.4; ADR-001.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-5.5
 */
final readonly class Frame
{
    public function __construct(
        public bool $fin,
        public Opcode $opcode,
        public string $payload,
        public bool $masked = false,
    ) {
        if ($opcode->isControl() && (!$fin || strlen($payload) > 125)) {
            throw InvalidMessageException::controlPayloadTooLarge();
        }
    }

    public static function text(string $payload, bool $fin = true): self
    {
        return new self($fin, Opcode::Text, $payload);
    }

    public static function binary(string $payload, bool $fin = true): self
    {
        return new self($fin, Opcode::Binary, $payload);
    }

    public static function continuation(string $payload, bool $fin = true): self
    {
        return new self($fin, Opcode::Continuation, $payload);
    }

    public static function close(CloseCode|int $code = CloseCode::NormalClosure, string $reason = ''): self
    {
        $value = $code instanceof CloseCode ? $code->value : $code;

        if (!CloseCode::isValid($value)) {
            throw InvalidMessageException::unsendableCloseCode($value);
        }

        if (strlen($reason) > 123) {
            throw InvalidMessageException::closeReasonTooLong();
        }

        if (preg_match('//u', $reason) !== 1) {
            throw InvalidMessageException::closeReasonNotUtf8();
        }

        return new self(true, Opcode::Close, pack('n', $value) . $reason);
    }

    public static function ping(string $payload = ''): self
    {
        return new self(true, Opcode::Ping, $payload);
    }

    public static function pong(string $payload = ''): self
    {
        return new self(true, Opcode::Pong, $payload);
    }

    public function length(): int
    {
        return strlen($this->payload);
    }
}
