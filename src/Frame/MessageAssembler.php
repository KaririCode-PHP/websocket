<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Frame;

use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\FrameException;
use KaririCode\WebSocket\Exception\WebSocketException;
use KaririCode\WebSocket\Message\Message;

/**
 * Reassembles fragmented data frames into complete {@see Message} objects.
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): second stage of the server's
 * data pipeline — {@see FrameParser} → this assembler → handler. Consumes
 * immutable Frame objects and produces immutable Message objects.
 *
 * Spec V4.0: final class. One assembler instance per connection;
 * state is scoped to the current fragmented message only.
 *
 * RFC 6455 §5.4: a message may be split across multiple frames.
 * The first frame carries the actual opcode (Text/Binary), followed
 * by zero or more Continuation frames. The final frame has FIN=1.
 *
 * Control frames (Ping/Pong/Close) may interleave within a
 * fragmented message — they are returned immediately without
 * affecting the reassembly state (RFC 6455 §5.4, last paragraph).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5.4 Fragmentation
 */
final class MessageAssembler
{
    private ?Opcode $fragmentOpcode = null;
    private string $fragmentBuffer = '';

    public function __construct(
        private readonly int $maxMessageSize = 10_485_760,
    ) {
    }

    /**
     * Feeds a parsed frame into the assembler.
     *
     * Returns a complete Message when the final frame is received,
     * or null if more frames are needed.
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5.4 Fragmentation
     */
    public function feed(Frame $frame): ?Message
    {
        // Control frames bypass the reassembly state
        if ($frame->opcode->isControl()) {
            return new Message($frame->opcode, $frame->payload);
        }

        // Start of a new message
        if ($frame->opcode !== Opcode::Continuation) {
            if ($this->fragmentOpcode !== null) {
                throw new WebSocketException(
                    CloseCode::ProtocolError,
                    'New data frame received while previous message is incomplete',
                );
            }

            if ($frame->fin) {
                // Single-frame message — validate and return
                $this->validateTextPayload($frame->opcode, $frame->payload);

                return new Message($frame->opcode, $frame->payload);
            }

            // Begin fragmented message
            $this->fragmentOpcode = $frame->opcode;
            $this->fragmentBuffer = $frame->payload;
            $this->validateBufferSize();

            return null;
        }

        // Continuation frame — must have an active fragment
        if ($this->fragmentOpcode === null) {
            throw new WebSocketException(
                CloseCode::ProtocolError,
                'Continuation frame without preceding data frame',
            );
        }

        $this->fragmentBuffer .= $frame->payload;
        $this->validateBufferSize();

        if (!$frame->fin) {
            return null;
        }

        // Final continuation frame — assemble complete message
        $opcode = $this->fragmentOpcode;
        $payload = $this->fragmentBuffer;

        $this->fragmentOpcode = null;
        $this->fragmentBuffer = '';

        $this->validateTextPayload($opcode, $payload);

        return new Message($opcode, $payload);
    }

    public function reset(): void
    {
        $this->fragmentOpcode = null;
        $this->fragmentBuffer = '';
    }

    public function isFragmenting(): bool
    {
        return $this->fragmentOpcode !== null;
    }

    private function validateBufferSize(): void
    {
        if (strlen($this->fragmentBuffer) > $this->maxMessageSize) {
            $this->reset();

            throw FrameException::payloadTooLarge(
                strlen($this->fragmentBuffer),
                $this->maxMessageSize,
            );
        }
    }

    private function validateTextPayload(Opcode $opcode, string $payload): void
    {
        if ($opcode === Opcode::Text && $payload !== '' && !mb_check_encoding($payload, 'UTF-8')) {
            throw FrameException::invalidUtf8();
        }
    }
}

