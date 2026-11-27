<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Message;

use KaririCode\WebSocket\Enum\Opcode;

/**
 * Assembled WebSocket message (RFC 6455 §5.4).
 *
 * ARFA 1.3 P1 (Immutable State Transformation): readonly class.
 *
 * Spec V4.0: final readonly class with static factories.
 *
 * A message may span multiple frames when fragmentation is used.
 * The {@see MessageAssembler} concatenates payloads from the initial
 * data frame through all continuation frames until FIN=1.
 *
 * For text messages, the complete payload MUST be valid UTF-8
 * (RFC 6455 §5.6). Validation occurs during assembly.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-6 Sending and Receiving Data
 */
final readonly class Message
{
    public function __construct(
        public Opcode $opcode,
        public string $payload,
    ) {
    }

    public function isText(): bool
    {
        return $this->opcode === Opcode::Text;
    }

    public function isBinary(): bool
    {
        return $this->opcode === Opcode::Binary;
    }

    public function length(): int
    {
        return strlen($this->payload);
    }

    public static function text(string $payload): self
    {
        return new self(Opcode::Text, $payload);
    }

    public static function binary(string $payload): self
    {
        return new self(Opcode::Binary, $payload);
    }
}

