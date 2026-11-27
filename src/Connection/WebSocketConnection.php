<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Connection;

use KaririCode\WebSocket\Contract\Connection;
use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\ConnectionState;
use KaririCode\WebSocket\Frame\Frame;
use KaririCode\WebSocket\Frame\FrameWriter;
use KaririCode\WebSocket\Message\Message;

/**
 * Wraps a raw TCP socket as a WebSocket connection (RFC 6455 §7.1).
 *
 * ARFA 1.3 P1: constructor parameters (id, address, path, writer) are
 * readonly — immutable once established. The mutable fields (state,
 * metadata, socket) are inherent to the connection lifecycle and exempt
 * from immutability per Spec V4.0 §2.2 (resource-owning types).
 *
 * Spec V4.0: final class. Manages the connection FSM
 * (Connecting → Open → Closing → Closed) and serializes outbound
 * frames via {@see FrameWriter}. The socket resource is owned by
 * this instance and closed on disconnect.
 *
 * transitionTo() is package-internal (used by {@see WebSocketServer}
 * during handshake). Application code uses send() and close() only.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5 Data Framing
 */
final class WebSocketConnection implements Connection
{
    private ConnectionState $state = ConnectionState::Open;

    /** @var array<string, mixed> */
    private array $metadata = [];

    /**
     * @param resource $socket  TCP socket resource
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5 Data Framing
     */
    public function __construct(
        private readonly string $connectionId,
        private $socket,
        private readonly string $remoteAddress,
        private readonly string $requestPath,
        private readonly FrameWriter $writer,
    ) {
    }

    // ── Connection Contract ──────────────────────────────────────────────

    public function id(): string
    {
        return $this->connectionId;
    }

    public function state(): ConnectionState
    {
        return $this->state;
    }

    public function remoteAddress(): string
    {
        return $this->remoteAddress;
    }

    public function path(): string
    {
        return $this->requestPath;
    }

    public function send(Message $message): void
    {
        if (!$this->state->canSendData()) {
            throw new \RuntimeException(
                "Cannot send data in {$this->state->name} state",
            );
        }

        $frame = new Frame(
            fin: true,
            opcode: $message->opcode,
            payload: $message->payload,
        );

        $this->writeFrame($frame);
    }

    public function close(CloseCode $code = CloseCode::NormalClosure, string $reason = ''): void
    {
        if ($this->state->isTerminal()) {
            return;
        }

        if ($this->state === ConnectionState::Open) {
            $this->state = ConnectionState::Closing;

            if ($code->isSendable()) {
                $this->writeFrame(Frame::close($code->numericCode(), $reason));
            }
        }

        $this->disconnect();
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    // ── Internal ─────────────────────────────────────────────────────────

    public function writeFrame(Frame $frame): void
    {
        if (!is_resource($this->socket)) {
            return;
        }

        $data = $this->writer->write($frame);
        $written = @fwrite($this->socket, $data);

        if ($written === false) {
            $this->disconnect();
        }
    }

    /**
     * @return resource|null
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5 Data Framing
     */
    public function socket()
    {
        return is_resource($this->socket) ? $this->socket : null;
    }

    public function disconnect(): void
    {
        $this->state = ConnectionState::Closed;

        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    public function transitionTo(ConnectionState $state): void
    {
        $this->state = $state;
    }
}

