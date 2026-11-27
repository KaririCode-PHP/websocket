<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Connection;

use KaririCode\WebSocket\Contract\Connection;
use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\ConnectionState;
use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\ConnectionException;
use KaririCode\WebSocket\Frame\Frame;
use KaririCode\WebSocket\Frame\FrameWriter;
use KaririCode\WebSocket\Handshake\HandshakeRequest;
use KaririCode\WebSocket\Message\Message;

/**
 * The server's connection object: what the handshake established, the
 * state of the closing handshake, and an outbox of encoded frames. send(),
 * ping() and close() only queue bytes; the server writes them when the
 * socket can take them, so a slow client never blocks the event loop and
 * a partial write never loses a frame. Once a Close frame is queued
 * nothing else is, as RFC 6455 §5.5.1 asks.
 *
 * ARFA 1.3: P2 (Message → frames → outbox). Spec V4.3.3: final; mutable
 * by nature (connection state), encapsulated.
 *
 * Preconditions: as Connection. takeOutput(), respondToClose(), pong()
 * and markClosed() belong to the server's session.
 *
 * Thread-safety: holds the state of one connection; use it from the event
 * loop only.
 *
 * Side effects: none; the server performs the I/O.
 *
 * Example:
 *
 *     $connection->send(Message::text('hello'));
 *     $bytes = $connection->takeOutput();   // "\x81\x05hello", for the socket
 *
 * References: RFC 6455 §5.5.1, §5.5.2, §5.5.3, §7.1.2; ADR-002.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-5.5.1
 */
final class WebSocketConnection implements Connection
{
    private ConnectionState $state = ConnectionState::Open;

    private string $outbox = '';

    /** @var array<string, mixed> */
    private array $attributes = [];

    private readonly FrameWriter $writer;

    public function __construct(
        private readonly string $id,
        private readonly string $remoteAddress,
        private readonly HandshakeRequest $request,
        private readonly ?string $subprotocol = null,
    ) {
        $this->writer = new FrameWriter();
    }

    #[\Override]
    public function id(): string
    {
        return $this->id;
    }

    #[\Override]
    public function state(): ConnectionState
    {
        return $this->state;
    }

    #[\Override]
    public function remoteAddress(): string
    {
        return $this->remoteAddress;
    }

    #[\Override]
    public function request(): HandshakeRequest
    {
        return $this->request;
    }

    #[\Override]
    public function path(): string
    {
        return $this->request->path();
    }

    #[\Override]
    public function subprotocol(): ?string
    {
        return $this->subprotocol;
    }

    #[\Override]
    public function send(Message $message): void
    {
        $this->queue(new Frame(true, $message->opcode, $message->payload));
    }

    #[\Override]
    public function ping(string $payload = ''): void
    {
        $this->queue(Frame::ping($payload));
    }

    #[\Override]
    public function close(CloseCode|int $code = CloseCode::NormalClosure, string $reason = ''): void
    {
        $frame = Frame::close($code, $reason);

        if ($this->state === ConnectionState::Open) {
            $this->outbox .= $this->writer->write($frame);
            $this->state = ConnectionState::Closing;
        }
    }

    #[\Override]
    public function attribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    #[\Override]
    public function putAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function attributes(): array
    {
        return $this->attributes;
    }

    /** The queued bytes, removed from the outbox. */
    public function takeOutput(): string
    {
        $output = $this->outbox;
        $this->outbox = '';

        return $output;
    }

    /** Answers a ping with the same payload (RFC 6455 §5.5.3); nothing once closing. */
    public function pong(string $payload): void
    {
        if ($this->state === ConnectionState::Open) {
            $this->outbox .= $this->writer->write(Frame::pong($payload));
        }
    }

    /**
     * Answers the client's Close by echoing its code, or an empty Close
     * when it sent none (RFC 6455 §5.5.1); nothing when the server already
     * sent its own.
     */
    public function respondToClose(?int $code): void
    {
        if ($this->state === ConnectionState::Open) {
            $this->outbox .= $this->writer->write(new Frame(true, Opcode::Close, $code === null ? '' : pack('n', $code)));
            $this->state = ConnectionState::Closing;
        }
    }

    public function markClosed(): void
    {
        $this->state = ConnectionState::Closed;
    }

    private function queue(Frame $frame): void
    {
        if (!$this->state->canSend()) {
            throw ConnectionException::notOpen();
        }

        $this->outbox .= $this->writer->write($frame);
    }
}
