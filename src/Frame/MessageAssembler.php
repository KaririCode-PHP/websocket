<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Frame;

use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\FrameException;
use KaririCode\WebSocket\Exception\InvalidConfigurationException;
use KaririCode\WebSocket\Exception\InvalidMessageException;
use KaririCode\WebSocket\Message\Message;

/**
 * Joins the data frames of one connection into messages (RFC 6455 §5.4):
 * a text or binary frame starts a message, continuation frames extend it,
 * the frame with FIN ends it. A continuation without a message, a new
 * message inside one and a message above the limit fail with the close
 * code to send; a text message is checked as UTF-8 once it is whole.
 * Control frames may arrive between fragments, but they are handled by the
 * caller and never fed here.
 *
 * ARFA 1.3: P2 (Frame → Message, the second stage of the read path).
 * Spec V4.3.3: final; one instance per connection, whose only state is
 * the message being assembled.
 *
 * Preconditions: $maxMessageSize ≥ 1 (InvalidConfigurationException
 * 1860). feed() takes data frames only (InvalidMessageException 1845).
 *
 * Thread-safety: holds the state of one connection; use it from one fiber
 * at a time.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $message = $assembler->feed($frame);   // null until the final fragment
 *     if ($message !== null) {
 *         $handler->onMessage($connection, $message);
 *     }
 *
 * References: RFC 6455 §5.4 (fragmentation), §5.6 (data frames), §8.1
 * (UTF-8), §10.4 (limits); ADR-001.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-5.4
 */
final class MessageAssembler
{
    private ?Opcode $opcode = null;

    private string $buffer = '';

    public function __construct(
        public readonly int $maxMessageSize = 10_485_760,
    ) {
        if ($maxMessageSize < 1) {
            throw InvalidConfigurationException::nonPositiveLimit('maxMessageSize');
        }
    }

    public function feed(Frame $frame): ?Message
    {
        if ($frame->opcode->isControl()) {
            throw InvalidMessageException::controlOpcodeInMessage();
        }

        $continuation = $frame->opcode === Opcode::Continuation;

        if ($continuation === ($this->opcode === null)) {
            throw $continuation ? FrameException::unexpectedContinuation() : FrameException::interleavedDataFrame();
        }

        if (strlen($this->buffer) + $frame->length() > $this->maxMessageSize) {
            $this->reset();

            throw FrameException::messageTooLarge();
        }

        $opcode = $this->opcode ?? $frame->opcode;
        $this->opcode = $opcode;
        $this->buffer .= $frame->payload;

        return $frame->fin ? $this->complete($opcode) : null;
    }

    public function isAssembling(): bool
    {
        return $this->opcode !== null;
    }

    public function reset(): void
    {
        $this->opcode = null;
        $this->buffer = '';
    }

    private function complete(Opcode $opcode): Message
    {
        $payload = $this->buffer;
        $this->reset();

        if ($opcode === Opcode::Binary) {
            return Message::binary($payload);
        }

        try {
            return Message::text($payload);
        } catch (InvalidMessageException) {
            throw FrameException::invalidUtf8();
        }
    }
}
