<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Frame;

use KaririCode\WebSocket\Enum\Opcode;

/**
 * Immutable representation of a single WebSocket wire frame (RFC 6455 §5).
 *
 * ARFA 1.3 P1 (Immutable State Transformation): readonly class — frames
 * are produced by {@see FrameParser} and consumed by
 * {@see MessageAssembler} without mutation.
 *
 * Spec V4.0: final readonly class with static factories for each
 * frame type (text, binary, close, ping, pong).
 *
 * Control frames (Close, Ping, Pong) are always single-frame.
 * Data frames may be fragmented across multiple frames via the
 * FIN bit and Continuation opcode (RFC 6455 §5.4).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5.2 Base Framing Protocol
 */
final readonly class Frame
{
    public function __construct(
        public bool $fin,
        public Opcode $opcode,
        public string $payload,
        public bool $masked = false,
    ) {
    }

    public function payloadLength(): int
    {
        return strlen($this->payload);
    }

    public static function text(string $payload, bool $fin = true): self
    {
        return new self($fin, Opcode::Text, $payload);
    }

    public static function binary(string $payload, bool $fin = true): self
    {
        return new self($fin, Opcode::Binary, $payload);
    }

    public static function close(int $code = 1000, string $reason = ''): self
    {
        $payload = pack('n', $code) . $reason;

        return new self(true, Opcode::Close, $payload);
    }

    public static function ping(string $payload = ''): self
    {
        return new self(true, Opcode::Ping, $payload);
    }

    public static function pong(string $payload = ''): self
    {
        return new self(true, Opcode::Pong, $payload);
    }
}

