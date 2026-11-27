# KaririCode WebSocket

An RFC 6455 WebSocket server for PHP 8.4: strict frame parsing and handshake,
the closing handshake, a connection pool, a single-process event loop, and
the bridge that lets [kariricode/router](https://github.com/KaririCode-PHP/router)
choose a handler by path. Every rule a client can break is checked, with
the close code RFC 6455 names.

[![CI](https://github.com/KaririCode-PHP/websocket/actions/workflows/ci.yml/badge.svg)](https://github.com/KaririCode-PHP/websocket/actions/workflows/ci.yml)

## Installation

```bash
composer require kariricode/websocket
```

Requires PHP 8.4. ext-openssl is suggested for `Integration\TlsContext`.

## A server

```php
use KaririCode\WebSocket\Contract\Connection;
use KaririCode\WebSocket\Contract\MessageHandler;
use KaririCode\WebSocket\Handshake\HandshakeNegotiator;
use KaririCode\WebSocket\Message\Message;
use KaririCode\WebSocket\Server\WebSocketServer;

final class EchoHandler implements MessageHandler
{
    public function onOpen(Connection $connection): void {}

    public function onMessage(Connection $connection, Message $message): void
    {
        $connection->send($message);
    }

    public function onClose(Connection $connection, int $code, string $reason): void {}

    public function onError(Connection $connection, \Throwable $error): void
    {
        error_log($error->getMessage());
    }
}

$server = new WebSocketServer(new EchoHandler(), new HandshakeNegotiator(allowedOrigins: ['https://app.example.com']));
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static fn() => $server->stop());   // Going Away (1001) to every client
$server->start('0.0.0.0', 8080);
```

A browser-facing server must list its origins: without the list, any site
can open a connection with the user's cookies. `listen()` and `tick()` let
an application drive the loop itself.

## Connections

A handler sees a `Connection`: its id, state, remote address, the handshake
request (`request()->header('cookie')`), the path and the subprotocol.
`send()` and `ping()` queue frames while the connection is open; the server
writes them as the socket takes them. `close($code, $reason)` starts the
closing handshake. Attributes keep application data per connection:

```php
public function onOpen(Connection $connection): void
{
    $connection->putAttribute('user', $this->auth->fromCookie($connection->request()->header('cookie')));
    $this->connections->broadcast(Message::text('someone joined'), static fn(Connection $c): bool => $c !== $connection);
}
```

`onClose` runs once per connection with the client's close code and
reason, 1005 when the client sent none, and 1006 when the connection ended
without a Close. An exception from `onOpen` or `onMessage` goes to
`onError` and closes the connection with 1011; the loop goes on.

## Routing

A connection becomes a router request with the pseudo-method `WEBSOCKET`
and the path of its handshake:

```php
use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Exception\RouteNotFoundException;
use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Integration\WebSocketNormalizer;

$routes = (new RouteRegistry())->route([WebSocketNormalizer::METHOD], '/chat/{room}', 'chat');

// in onOpen():
try {
    $request = (new WebSocketNormalizer())->normalize($connection);
    $match = $routes->match($request->method, $request->uri);
    $connection->putAttribute('room', $match->parameters->get('room'));
} catch (RouteNotFoundException) {
    $connection->close(CloseCode::PolicyViolation, 'unknown path');
}
```

## Limits

| Option | Default | Limits |
|---|---|---|
| `HandshakeNegotiator::$maxRequestSize` | 8192 bytes | the handshake head (431 above it) |
| `ServerOptions::$maxPayloadSize` | 1 MiB | one frame (1009, checked from its header) |
| `ServerOptions::$maxMessageSize` | 10 MiB | one message, all fragments (1009) |
| `ServerOptions::$maxBufferedOutput` | 16 MiB | unsent output per client (disconnected above it) |
| `ServerOptions::$closeTimeout` | 5 s | wait for the client's Close |
| `ServerOptions::$selectTimeout` | 0.2 s | longest wait of one round |
| `ServerOptions::$readChunk` | 64 KiB | bytes read per client per round |

The server runs in one process: handlers must return quickly, and
`stream_select()` handles descriptors below `FD_SETSIZE` (1024 on common
Linux builds). TLS (wss://) terminates in a reverse proxy, or in a loop of
your own built on `Integration\TlsContext`, which turns kariricode/http's
`TlsConfiguration` into a stream context.

## Errors

Each failure is a final exception in a kariricode/contract family, with a code
from block 1800–1899 (prefix `WS`):

| Exception | Family | Codes |
|---|---|---|
| `FrameException` | Protocol, with the close code sent | 1800–1814 |
| `HandshakeException` | Protocol, with the HTTP status sent | 1820–1831 |
| `InvalidMessageException` | Validation | 1840–1845 |
| `ConnectionException` | Runtime | 1850–1851 |
| `InvalidConfigurationException` | Configuration | 1860–1863 |
| `UnsupportedRequestException` | Validation | 1870 |

Messages name the rule that failed and never repeat a value the client sent;
a refused handshake gets a fixed response.

## Development

```bash
composer install
kcode init          # KaririCode Devkit 1.2.1: installs the pinned toolchain into .kcode/
composer check      # style, PHPStan (max), Psalm (1), complexity, tests, mutation
composer security   # composer audit (runtime and toolchain), secret scan
```

## Documentation

| Document | Topic |
|---|---|
| [SPEC-001](docs/spec/SPEC-001-websocket-architecture.md) | architecture and behaviour |
| [ADR-001](docs/adr/ADR-001-frame-protocol.md) | frames, messages, limits |
| [ADR-002](docs/adr/ADR-002-connections-and-handlers.md) | connections, handlers, closing, routing |
| [ADR-003](docs/adr/ADR-003-exception-families-and-code-block.md) | exception families, codes 1800–1899 |
| [ADR-004](docs/adr/ADR-004-event-loop.md) | the event loop |
| [ADR-005](docs/adr/ADR-005-handshake-validation.md) | handshake validation, origins |
| [ADR-006](docs/adr/ADR-006-toolchain-through-kcode.md) | quality gates |
| [Quality report](docs/quality/QD-V433-COMPLIANCE.md) | Spec V4.3.3 gates and deviations |
| [Security policy](SECURITY.md) | reporting, threat model |

## License

MIT. See [LICENSE](LICENSE).
