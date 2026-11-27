<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Server;

use KaririCode\WebSocket\Contract\Connection;
use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Exception\ConnectionException;
use KaririCode\WebSocket\Exception\WsCode;
use KaririCode\WebSocket\Handshake\HandshakeNegotiator;
use KaririCode\WebSocket\Message\Message;
use KaririCode\WebSocket\Server\ServerOptions;
use KaririCode\WebSocket\Server\Silently;
use KaririCode\WebSocket\Server\WebSocketServer;
use KaririCode\WebSocket\Tests\Support\Client;
use KaririCode\WebSocket\Tests\Support\RecordingHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The event loop over real loopback sockets: the test is the client and
 * drives the loop with tick().
 */
#[CoversClass(WebSocketServer::class)]
#[CoversClass(Silently::class)]
final class WebSocketServerTest extends TestCase
{
    /** @var list<resource> */
    private array $clients = [];

    private ?WebSocketServer $server = null;

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            if (is_resource($client)) {
                fclose($client);
            }
        }

        $this->server?->stop();
    }

    #[Test]
    public function aClientHandshakesAndExchangesMessages(): void
    {
        $handler = new RecordingHandler(onMessage: static function (Connection $connection, Message $message): void {
            $connection->send(Message::text('echo: ' . $message->payload));
        });
        $server = $this->server($handler);
        $client = $this->connect($server);

        fwrite($client, Client::handshake());
        $response = $this->readUntil($server, $client, "\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 101 Switching Protocols', $response);
        self::assertStringContainsString('Sec-WebSocket-Accept: ' . Client::ACCEPT, $response);
        self::assertCount(1, $server->connections());
        self::assertSame([stream_socket_get_name($client, false)], array_map(static fn(Connection $connection): string => $connection->remoteAddress(), array_values($server->connections()->all())));

        fwrite($client, Client::text('hi'));
        self::assertSame("\x81\x08echo: hi", $this->readBytes($server, $client, 10));
        self::assertSame(['open', 'message'], $handler->events);
    }

    #[Test]
    public function theClosingHandshakeEndsWithTheServerClosingTheSocket(): void
    {
        $handler = new RecordingHandler();
        $server = $this->server($handler);
        $client = $this->opened($server);

        fwrite($client, Client::close(1000, 'done'));

        self::assertSame("\x88\x02\x03\xE8", $this->readBytes($server, $client, 4));
        $this->tickUntil($server, static fn(): bool => $server->connections()->count() === 0);
        self::assertSame('', $this->readRest($server, $client), 'the server closed the socket');
        self::assertSame([[1000, 'done']], $handler->closes);
    }

    #[Test]
    public function aClientThatDisappearsIsReportedAs1006(): void
    {
        $handler = new RecordingHandler();
        $server = $this->server($handler);
        $client = $this->opened($server);

        fclose($client);
        $this->tickUntil($server, static fn(): bool => $handler->closes !== []);

        self::assertSame([[1006, '']], $handler->closes);
        self::assertCount(0, $server->connections());
    }

    #[Test]
    public function aResetConnectionIsReportedAs1006(): void
    {
        $handler = new RecordingHandler();
        $server = $this->server($handler);
        $client = $this->connect($server);

        fwrite($client, Client::handshake());
        $this->tickUntil($server, static fn(): bool => self::readable($client));
        fclose($client);   // the 101 response is unread, so the kernel resets the connection
        $this->tickUntil($server, static fn(): bool => $handler->closes !== []);

        self::assertSame([[1006, '']], $handler->closes);
        self::assertCount(0, $server->connections());
    }

    #[Test]
    public function aClientThatIgnoresTheServersCloseIsDroppedAfterTheCloseTimeout(): void
    {
        $handler = new RecordingHandler(onMessage: static function (Connection $connection): void {
            $connection->close(CloseCode::PolicyViolation, 'bye');
        });
        $server = $this->server($handler, options: new ServerOptions(selectTimeout: 0.01, closeTimeout: 0.3));
        $client = $this->opened($server);

        fwrite($client, Client::text('x'));
        self::assertSame("\x88\x05\x03\xF0bye", $this->readBytes($server, $client, 7));
        $started = hrtime(true);
        $this->tickUntil($server, static fn(): bool => $handler->closes !== []);

        self::assertGreaterThanOrEqual(200_000_000, hrtime(true) - $started, 'the server waited for the answer');
        self::assertSame([[1006, '']], $handler->closes);
        self::assertSame('', $this->readRest($server, $client), 'and then closed the socket');
    }

    #[Test]
    public function aRefusedHandshakeGetsItsStatusAndTheSocketCloses(): void
    {
        $server = $this->server(new RecordingHandler(), new HandshakeNegotiator(allowedOrigins: ['https://other.example']));
        $client = $this->connect($server);

        fwrite($client, Client::handshake());

        self::assertSame(HandshakeNegotiator::rejection(403), $this->readRest($server, $client));
        self::assertCount(0, $server->connections());
    }

    #[Test]
    public function aClientThatStopsReadingIsDroppedAtTheOutputLimit(): void
    {
        $handler = new RecordingHandler(onOpen: static function (Connection $connection): void {
            $connection->send(Message::binary(str_repeat('x', 1000)));
        });
        $server = $this->server($handler, options: new ServerOptions(maxBufferedOutput: 500, selectTimeout: 0.01));
        $client = $this->connect($server);

        fwrite($client, Client::handshake());
        $this->tickUntil($server, static fn(): bool => $handler->closes !== []);

        self::assertSame([[1006, '']], $handler->closes);
        self::assertCount(0, $server->connections());
    }

    #[Test]
    public function outputUpToTheLimitIsKept(): void
    {
        $negotiator = new HandshakeNegotiator();
        $accepted = $negotiator->negotiate($negotiator->parseRequest(Client::handshake()))->head;
        $handler = new RecordingHandler(onOpen: static function (Connection $connection): void {
            $connection->send(Message::binary(str_repeat('x', 1000)));
        });
        $limit = strlen($accepted) + 1004;   // the response and the frame, its 4-byte header included
        $server = $this->server($handler, $negotiator, new ServerOptions(maxBufferedOutput: $limit, selectTimeout: 0.01));
        $client = $this->connect($server);

        fwrite($client, Client::handshake());

        self::assertSame($limit, strlen($this->readBytes($server, $client, $limit)));
        self::assertSame([], $handler->closes);
        self::assertCount(1, $server->connections());
    }

    #[Test]
    public function aClientThatDoesNotReadDoesNotHoldUpTheOthers(): void
    {
        $handler = new RecordingHandler(onMessage: static function (Connection $connection, Message $message): void {
            $connection->send($message->payload === 'flood' ? Message::binary(str_repeat('x', 12_000_000)) : Message::text('echo: ' . $message->payload));
        });
        $server = $this->server($handler);
        $slow = $this->opened($server);
        $fast = $this->opened($server);

        fwrite($slow, Client::text('flood'));
        fwrite($fast, Client::text('hi'));

        self::assertSame("\x81\x08echo: hi", $this->readBytes($server, $fast, 10));

        fclose($slow);   // megabytes unread: a reset while the server still holds output for it
        $this->tickUntil($server, static fn(): bool => $handler->closes !== []);

        self::assertSame([[1006, '']], $handler->closes);
        self::assertCount(1, $server->connections());
    }

    #[Test]
    public function aQuietRoundWaitsForTheSelectTimeout(): void
    {
        $server = $this->server(new RecordingHandler(), options: new ServerOptions(selectTimeout: 0.05));

        $started = hrtime(true);
        $server->tick();

        self::assertGreaterThanOrEqual(40_000_000, hrtime(true) - $started, 'the round slept instead of spinning');
    }

    #[Test]
    public function stopSendsGoingAwayAndClosesEverything(): void
    {
        $handler = new RecordingHandler();
        $server = $this->server($handler);
        $client = $this->opened($server);

        $server->stop();

        self::assertSame("\x88\x0C\x03\xE9Going Away", fread($client, 64));
        self::assertSame([[1001, 'Going Away']], $handler->closes);
        self::assertNull($server->address());
        self::assertFalse($server->isRunning());
        $server->tick();
        self::assertCount(0, $server->connections());
    }

    #[Test]
    public function stopFromAHandlerEndsTheRoundCleanly(): void
    {
        /** @var \ArrayObject<string, WebSocketServer> $holder */
        $holder = new \ArrayObject();
        $handler = new RecordingHandler(onMessage: static function (Connection $connection, Message $message) use ($holder): void {
            $server = $holder->offsetGet('server');

            if ($message->payload === 'stop' && $server instanceof WebSocketServer) {
                $server->stop();
            } else {
                $connection->send(Message::text($message->payload));
            }
        });
        $server = $this->server($handler);
        $holder['server'] = $server;
        $first = $this->opened($server);
        $second = $this->opened($server);

        fwrite($second, Client::text('ab'));
        $this->tickUntil($server, static fn(): bool => $handler->messages !== []);   // the echo now waits for the next round
        fwrite($first, Client::text('stop'));
        fwrite($second, Client::text('late'));
        usleep(20_000);
        $server->tick();   // the first client's message stops the server; the second's is never read

        self::assertFalse($server->isRunning());
        self::assertCount(0, $server->connections());
        self::assertSame([[1001, 'Going Away'], [1001, 'Going Away']], $handler->closes);
        self::assertSame("\x81\x02ab\x88\x0C\x03\xE9Going Away", fread($second, 64), 'the pending echo, then Going Away');
    }

    #[Test]
    public function startRunsTheLoopUntilStop(): void
    {
        /** @var \ArrayObject<string, WebSocketServer> $holder */
        $holder = new \ArrayObject();
        $handler = new RecordingHandler(onOpen: static function () use ($holder): void {
            $server = $holder->offsetGet('server');

            if ($server instanceof WebSocketServer) {
                $server->stop();
            }
        });
        $server = new WebSocketServer($handler, options: new ServerOptions(selectTimeout: 0.05));
        $holder['server'] = $server;
        $port = self::freePort();
        $client = proc_open([PHP_BINARY, '-r', self::clientScript($port)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($client);

        $server->start('127.0.0.1', $port);

        self::assertFalse($server->isRunning());
        self::assertSame(['open', 'close'], $handler->events);
        self::assertStringStartsWith('HTTP/1.1 101', (string) stream_get_contents($pipes[1]));
        proc_close($client);
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function aSignalHandlerStopsTheLoopWithoutWaitingForTheSelectTimeout(): void
    {
        $server = new WebSocketServer(new RecordingHandler(), options: new ServerOptions(selectTimeout: 5.0));
        $async = pcntl_async_signals(true);
        pcntl_signal(SIGUSR1, static function () use ($server): void {
            $server->stop();
        });
        $signaller = proc_open([PHP_BINARY, '-r', sprintf('usleep(200000); posix_kill(%d, %d);', getmypid(), SIGUSR1)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($signaller);
        $started = hrtime(true);

        try {
            $server->start('127.0.0.1', 0);
        } finally {
            pcntl_signal(SIGUSR1, SIG_DFL);
            pcntl_async_signals($async);
            proc_close($signaller);
        }

        self::assertLessThan(3_000_000_000, hrtime(true) - $started, 'the signal cut the select short');
        self::assertFalse($server->isRunning());
        self::assertNull($server->address());
    }

    #[Test]
    public function anAddressInUseIsReported(): void
    {
        $server = $this->server(new RecordingHandler());
        $address = (string) $server->address();
        [$host, $port] = explode(':', $address);

        try {
            (new WebSocketServer(new RecordingHandler()))->listen($host, (int) $port);
            self::fail('a second server bound the same port');
        } catch (ConnectionException $e) {
            self::assertSame(WsCode::BindFailed, $e->errorCode);
        }

        self::assertMatchesRegularExpression('/\A127\.0\.0\.1:\d+\z/', $address);
        self::assertNull((new WebSocketServer(new RecordingHandler()))->address());
    }

    #[Test]
    public function aTickBeforeListenDoesNothing(): void
    {
        $server = new WebSocketServer(new RecordingHandler());

        $server->tick();

        self::assertCount(0, $server->connections());
        self::assertFalse($server->isRunning());
    }

    private function server(RecordingHandler $handler, HandshakeNegotiator $negotiator = new HandshakeNegotiator(), ServerOptions $options = new ServerOptions(selectTimeout: 0.01)): WebSocketServer
    {
        $server = new WebSocketServer($handler, $negotiator, $options);
        $server->listen('127.0.0.1', 0);
        $this->server = $server;

        return $server;
    }

    /**
     * @return resource
     */
    private function connect(WebSocketServer $server): mixed
    {
        $client = stream_socket_client('tcp://' . $server->address(), $code, $message, 2, STREAM_CLIENT_CONNECT, stream_context_create(['socket' => ['tcp_nodelay' => true]]));
        self::assertIsResource($client);
        stream_set_blocking($client, false);
        $this->clients[] = $client;
        $server->tick();

        return $client;
    }

    /**
     * @return resource
     */
    private function opened(WebSocketServer $server): mixed
    {
        $client = $this->connect($server);
        fwrite($client, Client::handshake());
        $this->readUntil($server, $client, "\r\n\r\n");

        return $client;
    }

    /**
     * @param resource $client
     */
    private function readUntil(WebSocketServer $server, mixed $client, string $marker): string
    {
        $data = '';
        $this->tickUntil($server, static function () use ($client, $marker, &$data): bool {
            $data .= (string) fread($client, 65536);

            return str_contains($data, $marker);
        });

        return $data;
    }

    /**
     * @param resource $client
     */
    private function readBytes(WebSocketServer $server, mixed $client, int $count): string
    {
        $data = '';
        $this->tickUntil($server, static function () use ($client, $count, &$data): bool {
            $data .= (string) fread($client, 65536);

            return strlen($data) >= $count;
        });

        return $data;
    }

    /**
     * @param resource $client
     */
    private function readRest(WebSocketServer $server, mixed $client): string
    {
        $data = '';
        $this->tickUntil($server, static function () use ($client, &$data): bool {
            $data .= (string) fread($client, 65536);

            return feof($client);
        });

        return $data;
    }

    /**
     * @param \Closure(): bool $done
     */
    private function tickUntil(WebSocketServer $server, \Closure $done): void
    {
        $deadline = microtime(true) + 5;

        while (!$done()) {
            self::assertLessThan($deadline, microtime(true), 'the server did not get there in time');
            $server->tick();
        }
    }

    /**
     * @param resource $client
     */
    private static function readable(mixed $client): bool
    {
        $read = [$client];
        $write = null;
        $except = null;

        return stream_select($read, $write, $except, 0) === 1;
    }

    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        return $port;
    }

    private static function clientScript(int $port): string
    {
        $handshake = var_export(Client::handshake(), true);

        return <<<PHP
            for (\$i = 0; \$i < 100; \$i++) {
                \$client = @stream_socket_client('tcp://127.0.0.1:{$port}', \$code, \$message, 1);
                if (is_resource(\$client)) { break; }
                usleep(20000);
            }
            fwrite(\$client, {$handshake});
            echo fread(\$client, 1024);
            PHP;
    }
}
