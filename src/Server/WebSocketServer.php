<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Server;

use KaririCode\WebSocket\Connection\ConnectionPool;
use KaririCode\WebSocket\Connection\WebSocketConnection;
use KaririCode\WebSocket\Contract\MessageHandler;
use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\ConnectionState;
use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\HandshakeException;
use KaririCode\WebSocket\Exception\WebSocketException;
use KaririCode\WebSocket\Frame\Frame;
use KaririCode\WebSocket\Frame\FrameParser;
use KaririCode\WebSocket\Frame\FrameWriter;
use KaririCode\WebSocket\Frame\MessageAssembler;
use KaririCode\WebSocket\Handshake\HandshakeNegotiator;
use KaririCode\WebSocket\Message\Message;

/**
 * Single-process WebSocket server using stream_select() for I/O multiplexing.
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): incoming data flows through
 * a composable pipeline — {@see FrameParser} → {@see MessageAssembler}
 * → {@see MessageHandler} — where each stage transforms immutable
 * value objects (Frame, Message).
 *
 * Spec V4.0: final class. Configuration is injected via constructor;
 * per-connection state (buffers, assemblers) is managed internally.
 *
 * For production deployments with 10k+ connections, replace this with
 * a Swoole/RoadRunner/ReactPHP adapter that implements the same
 * pipeline over non-blocking async I/O.
 *
 * Lifecycle per connection (RFC 6455 §4–7):
 *   1. Accept TCP → handshake (RFC 6455 §4.2)
 *   2. Read loop: parse frames → assemble messages → dispatch to handler
 *   3. Control frames: Ping→auto-Pong (§5.5.2), Close→echo+disconnect (§7.1)
 *   4. Close: send close frame → disconnect → remove from pool
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455 WebSocket Protocol
 */
final class WebSocketServer
{
    private readonly FrameParser $parser;
    private readonly FrameWriter $writer;
    private readonly ConnectionPool $pool;

    /** @var array<string, string> Per-connection read buffers */
    private array $readBuffers = [];

    /** @var array<string, MessageAssembler> Per-connection message assemblers */
    private array $assemblers = [];

    /** @var array<string, bool> Connections still in handshake phase */
    private array $pendingHandshake = [];

    /** @var resource|null */
    private $serverSocket = null;
    private bool $running = false;

    public function __construct(
        private readonly MessageHandler $handler,
        private readonly HandshakeNegotiator $handshake = new HandshakeNegotiator(),
        private readonly int $maxPayloadSize = 1_048_576,
        private readonly int $maxMessageSize = 10_485_760,
        private readonly float $selectTimeout = 0.2,
    ) {
        $this->parser = new FrameParser($this->maxPayloadSize);
        $this->writer = new FrameWriter();
        $this->pool = new ConnectionPool();
    }

    public function connections(): ConnectionPool
    {
        return $this->pool;
    }

    /**
     * Starts the server and enters the event loop (blocking).
     *
     * @param string $host Bind address (0.0.0.0 for all interfaces)
     * @param int    $port TCP port (1–65535)
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455 WebSocket Protocol
     */
    public function start(string $host, int $port): void
    {
        $address = "tcp://{$host}:{$port}";
        $context = stream_context_create(['socket' => ['backlog' => 128]]);

        $this->serverSocket = @stream_socket_server(
            $address,
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($this->serverSocket === false) {
            throw new \RuntimeException("Failed to bind {$address}: [{$errorCode}] {$errorMessage}");
        }

        stream_set_blocking($this->serverSocket, false);
        $this->running = true;

        $this->eventLoop();
    }

    public function stop(): void
    {
        $this->running = false;

        // Close all connections gracefully
        foreach ($this->pool->all() as $connection) {
            $connection->close(CloseCode::GoingAway, 'Server shutting down');
        }

        $this->pool->clear();
        $this->readBuffers = [];
        $this->assemblers = [];
        $this->pendingHandshake = [];

        if (is_resource($this->serverSocket)) {
            fclose($this->serverSocket);
            $this->serverSocket = null;
        }
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    // ── Event Loop ───────────────────────────────────────────────────────

    private function eventLoop(): void
    {
        while ($this->running) {
            $readSockets = $this->buildReadSet();
            $writeSockets = null;
            $exceptSockets = null;

            $changed = @stream_select(
                $readSockets,
                $writeSockets,
                $exceptSockets,
                0,
                (int) ($this->selectTimeout * 1_000_000),
            );

            if ($changed === false || $changed === 0) {
                continue;
            }

            foreach ($readSockets as $socket) {
                if ($socket === $this->serverSocket) {
                    $this->acceptConnection();
                } else {
                    $this->handleSocketData($socket);
                }
            }
        }
    }

    /**
     * @return list<resource>
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455 WebSocket Protocol
     */
    private function buildReadSet(): array
    {
        $sockets = [$this->serverSocket];

        foreach ($this->pool->all() as $connection) {
            if ($connection instanceof WebSocketConnection) {
                $socket = $connection->socket();
                if ($socket !== null) {
                    $sockets[] = $socket;
                }
            }
        }

        return $sockets;
    }

    // ── Connection Acceptance ────────────────────────────────────────────

    private function acceptConnection(): void
    {
        $clientSocket = @stream_socket_accept($this->serverSocket, 0, $peerName);
        if ($clientSocket === false) {
            return;
        }

        stream_set_blocking($clientSocket, false);

        $connectionId = bin2hex(random_bytes(8));

        $connection = new WebSocketConnection(
            $connectionId,
            $clientSocket,
            $peerName ?? 'unknown',
            '/',
            $this->writer,
        );

        $connection->transitionTo(ConnectionState::Connecting);
        $this->pool->add($connection);
        $this->readBuffers[$connectionId] = '';
        $this->assemblers[$connectionId] = new MessageAssembler($this->maxMessageSize);
        $this->pendingHandshake[$connectionId] = true;
    }

    // ── Data Handling ────────────────────────────────────────────────────

    /**
     * @param resource $socket
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455 WebSocket Protocol
     */
    private function handleSocketData($socket): void
    {
        $connection = $this->findConnectionBySocket($socket);
        if ($connection === null) {
            return;
        }

        $connectionId = $connection->id();
        $data = @fread($socket, 65536);

        if ($data === false || $data === '') {
            $this->removeConnection($connectionId, CloseCode::AbnormalClosure, 'Connection lost');
            return;
        }

        $this->readBuffers[$connectionId] .= $data;

        // Handshake phase
        if (isset($this->pendingHandshake[$connectionId])) {
            $this->processHandshake($connection);
            return;
        }

        // Frame parsing phase
        $this->processFrames($connection);
    }

    private function processHandshake(WebSocketConnection $connection): void
    {
        $connectionId = $connection->id();
        $buffer = $this->readBuffers[$connectionId];

        // Wait for complete HTTP request (\r\n\r\n)
        if (strpos($buffer, "\r\n\r\n") === false) {
            return;
        }

        try {
            $parsed = $this->handshake->parseRequest($buffer);
            $response = $this->handshake->negotiate($parsed['headers']);

            $socket = $connection->socket();
            if ($socket === null) {
                $this->removeConnection($connectionId, CloseCode::AbnormalClosure);
                return;
            }

            fwrite($socket, $response);

            $connection->transitionTo(ConnectionState::Open);
            unset($this->pendingHandshake[$connectionId]);
            $this->readBuffers[$connectionId] = '';

            $this->handler->onOpen($connection);

        } catch (HandshakeException $e) {
            $socket = $connection->socket();
            if ($socket !== null) {
                $errorResponse = "HTTP/1.1 {$e->httpStatusCode} {$e->getMessage()}\r\n"
                    . "Connection: close\r\n\r\n";
                @fwrite($socket, $errorResponse);
            }
            $this->removeConnection($connectionId, CloseCode::ProtocolError, $e->getMessage());
        }
    }

    private function processFrames(WebSocketConnection $connection): void
    {
        $connectionId = $connection->id();

        while (true) {
            $buffer = $this->readBuffers[$connectionId] ?? '';
            if ($buffer === '') {
                break;
            }

            try {
                $result = $this->parser->parse($buffer);
            } catch (WebSocketException $e) {
                $this->handler->onError($connection, $e);
                $this->removeConnection($connectionId, $e->closeCode, $e->getMessage());
                return;
            }

            if ($result === null) {
                break; // Incomplete frame — wait for more data
            }

            [$frame, $consumed] = $result;
            $this->readBuffers[$connectionId] = substr($buffer, $consumed);

            $this->handleFrame($connection, $frame);

            if ($connection->state()->isTerminal()) {
                return;
            }
        }
    }

    private function handleFrame(WebSocketConnection $connection, Frame $frame): void
    {
        $connectionId = $connection->id();

        // RFC 6455 §5.5.2: respond to Ping with matching Pong
        if ($frame->opcode === Opcode::Ping) {
            $connection->writeFrame(Frame::pong($frame->payload));
            return;
        }

        if ($frame->opcode === Opcode::Pong) {
            return; // Unsolicited pongs are ignored per RFC 6455 §5.5.3
        }

        if ($frame->opcode === Opcode::Close) {
            $code = CloseCode::NormalClosure;
            $reason = '';
            if (strlen($frame->payload) >= 2) {
                $codeValue = unpack('n', substr($frame->payload, 0, 2))[1];
                $code = CloseCode::tryFromCode($codeValue) ?? CloseCode::NormalClosure;
                $reason = substr($frame->payload, 2);
            }

            $this->removeConnection($connectionId, $code, $reason);
            return;
        }

        // Data frames → assembler
        try {
            $assembler = $this->assemblers[$connectionId] ?? null;
            if ($assembler === null) {
                return;
            }

            $message = $assembler->feed($frame);
            if ($message !== null) {
                $this->handler->onMessage($connection, $message);
            }
        } catch (WebSocketException $e) {
            $this->handler->onError($connection, $e);
            $this->removeConnection($connectionId, $e->closeCode, $e->getMessage());
        }
    }

    // ── Connection Cleanup ───────────────────────────────────────────────

    private function removeConnection(string $connectionId, CloseCode $code = CloseCode::NormalClosure, string $reason = ''): void
    {
        $connection = $this->pool->remove($connectionId);

        if ($connection !== null) {
            $this->handler->onClose($connection, $code->numericCode(), $reason);
            $connection->close($code, $reason);
        }

        unset(
            $this->readBuffers[$connectionId],
            $this->assemblers[$connectionId],
            $this->pendingHandshake[$connectionId],
        );
    }

    /**
     * @param resource $socket
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455 WebSocket Protocol
     */
    private function findConnectionBySocket($socket): ?WebSocketConnection
    {
        foreach ($this->pool->all() as $connection) {
            if ($connection instanceof WebSocketConnection && $connection->socket() === $socket) {
                return $connection;
            }
        }

        return null;
    }
}

