<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Server;

use KaririCode\WebSocket\Connection\ConnectionPool;
use KaririCode\WebSocket\Contract\MessageHandler;
use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Exception\ConnectionException;
use KaririCode\WebSocket\Handshake\HandshakeNegotiator;

/**
 * A single-process WebSocket server on non-blocking sockets and
 * stream_select(). Each round of tick() accepts new clients, feeds the
 * bytes it reads to their sessions, writes as much pending output as each
 * socket takes (the rest waits for the next round, so a partial write
 * never loses a frame), and closes the sockets whose sessions are over, a
 * client that holds more unsent output than the limit, and a client that
 * does not answer the server's Close in time. start() binds and loops
 * until stop(); listen() and tick() let a caller drive the loop itself.
 *
 * ARFA 1.3: P2 (sockets ↔ sessions). Spec V4.3.3: final; the socket state
 * of one process.
 *
 * Preconditions: the handler's methods return quickly (they run inside
 * the loop). TLS terminates in front of the server (a reverse proxy), or
 * the caller builds its own loop on Integration\TlsContext; the loop
 * itself speaks plain TCP. For many thousands of connections, an event
 * loop extension or another runtime fits better than stream_select().
 *
 * Thread-safety: one process, one thread; not shareable.
 *
 * Side effects: binds a TCP socket, accepts, reads, writes and closes
 * client sockets; calls the handler.
 *
 * Example:
 *
 *     $server = new WebSocketServer(new ChatHandler(), new HandshakeNegotiator(allowedOrigins: ['https://chat.example.com']));
 *     pcntl_async_signals(true);
 *     pcntl_signal(SIGTERM, static fn() => $server->stop());
 *     $server->start('0.0.0.0', 8080);
 *
 * References: RFC 6455 §4.2, §7.1.1 (the server closes the TCP connection
 * first); PHP manual, stream_socket_server(), stream_select(); ADR-004.
 *
 * @see https://www.php.net/manual/en/function.stream-select.php
 */
final class WebSocketServer
{
    /** @var resource|null */
    private mixed $socket = null;

    /** @var array<int, array{resource, Session}> client socket id => socket and session */
    private array $clients = [];

    private bool $running = false;

    private readonly ServerContext $context;

    public function __construct(
        MessageHandler $handler,
        HandshakeNegotiator $negotiator = new HandshakeNegotiator(),
        ServerOptions $options = new ServerOptions(),
    ) {
        $this->context = new ServerContext($handler, $negotiator, new ConnectionPool(), $options);
    }

    public function connections(): ConnectionPool
    {
        return $this->context->pool;
    }

    /**
     * Binds and listens on $host:$port; port 0 takes a free port, which
     * address() reports. Accepted sockets have TCP_NODELAY: frames are
     * written whole, so Nagle's algorithm would only delay them.
     */
    public function listen(string $host, int $port): void
    {
        $address = sprintf('tcp://%s:%d', $host, $port);
        $context = stream_context_create(['socket' => ['backlog' => 128, 'tcp_nodelay' => true]]);
        $socket = Silently::run(static fn(): mixed => stream_socket_server($address, $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context));

        if (!is_resource($socket)) {
            throw ConnectionException::bindFailed($address);
        }

        $this->socket = $socket;
    }

    /** "host:port" of the listening socket, or null before listen() and after stop(). */
    public function address(): ?string
    {
        $address = $this->socket === null ? false : stream_socket_get_name($this->socket, false);

        return $address === false ? null : $address;
    }

    /** Listens and runs the loop until stop(). */
    public function start(string $host, int $port): void
    {
        $this->listen($host, $port);
        $this->running = true;

        while ($this->isRunning()) {
            $this->tick();
        }
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * One round of the loop: waits up to the select timeout for sockets,
     * then serves them. Without a listening socket (before listen(), after
     * stop()) the round does nothing and the loop of start() ends.
     */
    public function tick(): void
    {
        if ($this->socket === null) {
            $this->running = false;

            return;
        }

        [$readable, $writable] = $this->ready($this->socket);

        foreach ($readable as $socket) {
            if ($socket === $this->socket) {
                $this->accept($socket);
            } else {
                $this->read($socket);
            }
        }

        foreach ($writable as $socket) {
            $this->write($socket);
        }

        $this->sweep(((float) hrtime(true)) / 1e9);
    }

    /**
     * Sends Going Away (1001) to every open connection, writes what each
     * socket takes at once, and closes every socket; the loop of start()
     * ends.
     */
    public function stop(): void
    {
        $this->running = false;

        foreach ($this->clients as [$socket, $session]) {
            $session->shutdown(CloseCode::GoingAway);
            $pending = $session->pending();
            Silently::run(static fn(): int|false => fwrite($socket, $pending));
            $this->drop($socket, $session);
        }

        if ($this->socket !== null) {
            $socket = $this->socket;
            $this->socket = null;
            Silently::run(static fn(): bool => fclose($socket));
        }
    }

    /**
     * @param resource $server
     *
     * @return array{array<array-key, resource>, array<array-key, resource>} readable and writable sockets
     */
    private function ready(mixed $server): array
    {
        $read = [$server];
        $write = [];

        foreach ($this->clients as [$socket, $session]) {
            $read[] = $socket;

            if ($session->pending() !== '') {
                $write[] = $socket;
            }
        }

        $except = null;
        $timeout = $this->context->options->selectTimeout;
        $seconds = (int) $timeout;
        $microseconds = (int) (fmod($timeout, 1.0) * 1e6);
        $changed = Silently::run(static function () use (&$read, &$write, &$except, $seconds, $microseconds): int|false {
            return stream_select($read, $write, $except, $seconds, $microseconds);
        });

        return $changed === false ? [[], []] : [$read, $write];
    }

    /**
     * @param resource $server
     */
    private function accept(mixed $server): void
    {
        $peer = '';
        $client = Silently::run(static function () use ($server, &$peer): mixed {
            return stream_socket_accept($server, 0, $peer);
        });

        if (!is_resource($client)) {
            return;
        }

        stream_set_blocking($client, false);
        $this->clients[(int) $client] = [$client, new Session(self::text($peer), $this->context)];
    }

    /**
     * @param resource $socket
     */
    private function read(mixed $socket): void
    {
        $session = $this->session($socket);

        if ($session === null) {
            return;
        }

        $chunk = $this->context->options->readChunk;
        $data = Silently::run(static fn(): string|false => fread($socket, $chunk));

        if ($data === '' && !feof($socket)) {
            return;
        }

        if ($data === false || $data === '') {
            $this->drop($socket, $session);

            return;
        }

        $session->receive($data);
    }

    /**
     * @param resource $socket
     */
    private function write(mixed $socket): void
    {
        $session = $this->session($socket);

        if ($session === null) {
            return;   // read() dropped it in this round
        }

        $pending = $session->pending();
        $written = Silently::run(static fn(): int|false => fwrite($socket, $pending));

        if ($written === false) {
            $this->drop($socket, $session);
        } else {
            $session->written($written);
        }
    }

    /** Closes the sockets whose sessions are over or hold too much unsent output. */
    private function sweep(float $now): void
    {
        $options = $this->context->options;

        foreach ($this->clients as [$socket, $session]) {
            $session->tick($now, $options->closeTimeout);

            if ($session->isDone() || strlen($session->pending()) > $options->maxBufferedOutput) {
                $this->drop($socket, $session);
            }
        }
    }

    /**
     * @param resource $socket
     */
    private function drop(mixed $socket, Session $session): void
    {
        unset($this->clients[(int) $socket]);
        $connection = $session->connection();

        if ($connection !== null) {
            $this->context->pool->remove($connection->id());
        }

        $session->end();
        Silently::run(static fn(): bool => fclose($socket));
    }

    /**
     * @param resource $socket
     */
    private function session(mixed $socket): ?Session
    {
        $entry = $this->clients[(int) $socket] ?? null;

        return $entry === null ? null : $entry[1];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
