<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Server;

use KaririCode\WebSocket\Connection\WebSocketConnection;
use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\ConnectionState;
use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\FrameException;
use KaririCode\WebSocket\Exception\HandshakeException;
use KaririCode\WebSocket\Frame\ClosePayload;
use KaririCode\WebSocket\Frame\Frame;
use KaririCode\WebSocket\Frame\FrameParser;
use KaririCode\WebSocket\Frame\MessageAssembler;
use KaririCode\WebSocket\Handshake\HandshakeNegotiator;

/**
 * The protocol of one client socket, without the socket: bytes in through
 * receive(), bytes out through pending() and written(). It runs the
 * opening handshake (a refusal gets a fixed HTTP response and the socket
 * closes), then the frames: pings get pongs, pongs are ignored, a Close is
 * echoed and the server closes first (RFC 6455 §7.1.1), data frames become
 * messages, and a protocol error sends a Close with its code before the
 * socket closes (§7.1.7). Bytes the client sends right after its handshake
 * are kept for the frame parser. The handler hears onOpen, onMessage and
 * onError, and onClose exactly once, with 1006 when the TCP connection ends
 * without a Close from the client. An exception from the handler closes
 * the connection with 1011 instead of stopping the server.
 *
 * ARFA 1.3: P2 (bytes → frames → messages → handler, and back). Spec
 * V4.3.3: final; the per-connection state lives here, the I/O in
 * WebSocketServer.
 *
 * Preconditions: one session per accepted socket; the server calls tick()
 * every loop round and end() when it closes the socket.
 *
 * Thread-safety: holds the state of one connection; use it from the event
 * loop only.
 *
 * Side effects: calls the handler; adds the connection to the pool when
 * the handshake succeeds.
 *
 * Example:
 *
 *     $session->receive(fread($socket, 65536));
 *     $written = fwrite($socket, $session->pending());
 *     $session->written($written);
 *
 * References: RFC 6455 §4.2, §5.4, §5.5, §7.1.1–§7.1.7, §7.4.1; ADR-002,
 * ADR-004.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-7.1
 */
final class Session
{
    private string $input = '';

    private string $output = '';

    private ?WebSocketConnection $connection = null;

    /** The socket closes once the output is written. */
    private bool $finished = false;

    private bool $reported = false;

    private ?float $closingSince = null;

    private readonly FrameParser $parser;

    private readonly MessageAssembler $assembler;

    public function __construct(
        private readonly string $remoteAddress,
        private readonly ServerContext $context,
    ) {
        $this->parser = new FrameParser($context->options->maxPayloadSize);
        $this->assembler = new MessageAssembler($context->options->maxMessageSize);
    }

    public function connection(): ?WebSocketConnection
    {
        return $this->connection;
    }

    public function receive(string $bytes): void
    {
        if ($this->finished) {
            return;
        }

        $this->input .= $bytes;

        if ($this->connection === null) {
            $this->handshake();

            return;
        }

        $this->frames($this->connection);
    }

    /** The bytes waiting for the socket, the connection's outbox included. */
    public function pending(): string
    {
        $this->output .= $this->connection?->takeOutput() ?? '';

        return $this->output;
    }

    public function written(int $bytes): void
    {
        $this->output = substr($this->output, $bytes);
    }

    /** True when the socket should close now: the session is over and its output is written. */
    public function isDone(): bool
    {
        return $this->finished && $this->pending() === '';
    }

    /** Gives up on a client that does not answer the server's Close within $timeout seconds. */
    public function tick(float $now, float $timeout): void
    {
        if ($this->finished || $this->connection?->state() !== ConnectionState::Closing) {
            return;
        }

        $this->closingSince ??= $now;
        $this->finished = $now - $this->closingSince >= $timeout;
    }

    /** Queues a Close with $code for an open connection and ends the session (server shutdown). */
    public function shutdown(CloseCode $code): void
    {
        $this->connection?->close($code, $code->reason());
        $this->finished = true;
        $this->report($code->value, $code->reason());
    }

    /** The socket is closed: the connection is Closed, and onClose hears 1006 if nothing was reported. */
    public function end(): void
    {
        $this->finished = true;
        $this->output = '';
        $this->connection?->markClosed();
        $this->report(CloseCode::AbnormalClosure->value, '');
    }

    private function handshake(): void
    {
        $negotiator = $this->context->negotiator;

        try {
            $head = $negotiator->head($this->input);

            if ($head === null) {
                return;
            }

            $request = $negotiator->parseRequest($head);
            $response = $negotiator->negotiate($request);
        } catch (HandshakeException $e) {
            $this->output = HandshakeNegotiator::rejection($e->status);
            $this->finished = true;

            return;
        }

        $this->input = substr($this->input, strlen($head));
        $this->output = $response->head;
        $connection = new WebSocketConnection(bin2hex(random_bytes(8)), $this->remoteAddress, $request, $response->subprotocol);
        $this->connection = $connection;
        $this->context->pool->add($connection);
        $this->deliver($connection, function () use ($connection): void {
            $this->context->handler->onOpen($connection);
        });
        $this->frames($connection);
    }

    private function frames(WebSocketConnection $connection): void
    {
        while (!$this->finished && ($frame = $this->nextFrame($connection)) !== null) {
            if ($frame->opcode === Opcode::Ping) {
                $connection->pong($frame->payload);
            } elseif ($frame->opcode === Opcode::Close) {
                $this->closeReceived($connection, $frame->payload);
            } elseif ($frame->opcode !== Opcode::Pong) {
                $this->data($connection, $frame);
            }
        }
    }

    private function nextFrame(WebSocketConnection $connection): ?Frame
    {
        try {
            $result = $this->parser->parse($this->input);
        } catch (FrameException $e) {
            $this->fail($connection, $e);

            return null;
        }

        if ($result === null) {
            return null;
        }

        $this->input = substr($this->input, $result[1]);

        return $result[0];
    }

    /** Data after the server sent its Close is discarded (RFC 6455 §1.4). */
    private function data(WebSocketConnection $connection, Frame $frame): void
    {
        if ($connection->state() !== ConnectionState::Open) {
            return;
        }

        try {
            $message = $this->assembler->feed($frame);
        } catch (FrameException $e) {
            $this->fail($connection, $e);

            return;
        }

        if ($message !== null) {
            $this->deliver($connection, function () use ($connection, $message): void {
                $this->context->handler->onMessage($connection, $message);
            });
        }
    }

    private function closeReceived(WebSocketConnection $connection, string $payload): void
    {
        try {
            $close = ClosePayload::parse($payload);
        } catch (FrameException $e) {
            $this->fail($connection, $e);

            return;
        }

        $connection->respondToClose($close->code);
        $this->finished = true;
        $this->report($close->status(), $close->reason);
    }

    /** Fails the connection (RFC 6455 §7.1.7): Close with the code of the rule, then the socket closes. */
    private function fail(WebSocketConnection $connection, FrameException $e): void
    {
        $this->notify($connection, $e);
        $connection->close($e->closeCode, $e->closeCode->reason());
        $this->finished = true;
        $this->report($e->closeCode->value, $e->closeCode->reason());
    }

    /**
     * Runs a handler callback; an exception from it closes the connection with 1011.
     *
     * @param \Closure(): void $callback
     */
    private function deliver(WebSocketConnection $connection, \Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->notify($connection, $e);
            $connection->close(CloseCode::InternalError, CloseCode::InternalError->reason());
        }
    }

    private function notify(WebSocketConnection $connection, \Throwable $error): void
    {
        try {
            $this->context->handler->onError($connection, $error);
        } catch (\Throwable) {
            // The error channel itself failed; nothing is left to report to, and the loop must go on.
        }
    }

    private function report(int $code, string $reason): void
    {
        $connection = $this->connection;

        if ($this->reported || $connection === null) {
            return;
        }

        $this->reported = true;

        try {
            $this->context->handler->onClose($connection, $code, $reason);
        } catch (\Throwable $e) {
            $this->notify($connection, $e);
        }
    }
}
