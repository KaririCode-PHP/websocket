<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Message;

use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\InvalidMessageException;

/**
 * A whole WebSocket message, received or to send: text, which RFC 6455
 * requires to be UTF-8, or binary, which may hold any bytes. The two named
 * constructors are the only way to build one, so a message always has a
 * data opcode and text is always valid UTF-8.
 *
 * ARFA 1.3: P1 (immutable). Spec V4.3.3: final readonly Value Object.
 *
 * Preconditions: text() takes valid UTF-8 (InvalidMessageException 1840).
 *
 * Thread-safety: immutable; safe to share, also between connections in a
 * broadcast.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $connection->send(Message::text(json_encode(['event' => 'joined'], JSON_THROW_ON_ERROR)));
 *     $connection->send(Message::binary($pngBytes));
 *
 * References: RFC 6455 §5.6 (data frames), §8.1 (UTF-8); ADR-001.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-5.6
 */
final readonly class Message
{
    private function __construct(
        public Opcode $opcode,
        public string $payload,
    ) {}

    public static function text(string $payload): self
    {
        return preg_match('//u', $payload) === 1 ? new self(Opcode::Text, $payload) : throw InvalidMessageException::textNotUtf8();
    }

    public static function binary(string $payload): self
    {
        return new self(Opcode::Binary, $payload);
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
}
