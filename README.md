# KaririCode WebSocket

RFC 6455 compliant WebSocket server library for PHP 8.4+ with frame parsing/writing, handshake negotiation, connection pool management, message handler abstraction, and Router V4.1 integration.

## Overview

KaririCode WebSocket provides the full server-side WebSocket stack: binary frame protocol (parsing and writing), opening handshake negotiation, connection lifecycle state machine, message fragmentation/reassembly, and a handler abstraction for application logic. It is I/O-layer agnostic — works with stream_socket, Swoole, or ReactPHP.

## Features

- **RFC 6455 compliant** — frame protocol, handshake, close codes, masking, fragmentation
- **Frame parser/writer** — configurable payload limits, mask enforcement, UTF-8 validation
- **Connection pool** — add, remove, broadcast, iteration over managed connections
- **State machine** — Connecting → Open → Closing → Closed with validated transitions
- **Message assembler** — transparent fragmented message reassembly
- **Handshake negotiation** — key validation, origin allowlist, subprotocol negotiation
- **Handler abstraction** — `onOpen`, `onMessage`, `onClose`, `onError` callbacks
- **WSS support** — `TlsContext` for secure WebSocket connections
- **Router integration** — `WebSocketNormalizer` bridges to Router V4.1
- **Zero external deps** — only `kariricode/contract` required

## Installation

```bash
composer require kariricode/websocket
```

## Quick Start

```php
use KaririCode\WebSocket\Server\WebSocketServer;
use KaririCode\WebSocket\Handler\AbstractMessageHandler;
use KaririCode\WebSocket\Message\Message;
use KaririCode\WebSocket\Contract\Connection;

$handler = new class extends AbstractMessageHandler {
    public function onMessage(Connection $conn, Message $message): void
    {
        // Echo received message back
        $conn->send($message->payload);
    }
};

$server = new WebSocketServer($handler);
$server->start('0.0.0.0', 8080);
```

## Frame Protocol

```php
use KaririCode\WebSocket\Frame\{FrameParser, FrameWriter, Frame};
use KaririCode\WebSocket\Enum\Opcode;

// Parse incoming frame
$parser = new FrameParser(maxPayloadLength: 1_048_576);
[$frame, $consumed] = $parser->parse($buffer);

// Write outgoing frame
$writer = new FrameWriter();
$bytes = $writer->write(new Frame(
    fin: true,
    opcode: Opcode::Text,
    payload: '{"event":"update"}',
));
```

## Connection Pool

```php
$pool = $server->connections();

// Broadcast to all open connections
$pool->broadcast($frame);

// Iterate connections
foreach ($pool->all() as $connection) {
    if ($connection->state() === ConnectionState::Open) {
        $connection->send('ping');
    }
}
```

## Close Codes

```php
use KaririCode\WebSocket\Enum\CloseCode;

$connection->close(CloseCode::NormalClosure, 'Goodbye');
$connection->close(CloseCode::GoingAway, 'Server shutting down');
```

## Router Integration

```php
use KaririCode\WebSocket\Integration\WebSocketNormalizer;

$normalizer = new WebSocketNormalizer();
$router->route('WS_CONNECT', '/ws/chat', $chatHandler);
$router->route('WS_CONNECT', '/ws/notifications', $notifyHandler);
```

## Error Handling

| Exception | When |
|---|---|
| `FrameException::reservedOpcode()` | Unknown opcode received |
| `FrameException::controlFrameTooLarge()` | Control frame > 125 bytes |
| `FrameException::payloadTooLarge()` | Payload exceeds limit |
| `FrameException::maskRequired()` | Client frame missing mask |
| `FrameException::invalidUtf8()` | Text frame with invalid UTF-8 |
| `HandshakeException::missingHeaders()` | Upgrade headers missing |
| `HandshakeException::invalidKey()` | Bad Sec-WebSocket-Key |
| `HandshakeException::unsupportedVersion()` | Version ≠ 13 |
| `HandshakeException::originRejected()` | Origin not in allowlist |

## Testing

```bash
./vendor/bin/phpunit
```

## Documentation

| Document | Location |
|---|---|
| ADR-001: Frame Protocol | `docs/adr/ADR-001-frame-protocol.md` |
| ADR-002: Connection Pool | `docs/adr/ADR-002-connection-pool.md` |
| SPEC-001: Architecture | `docs/spec/SPEC-001-websocket-architecture.md` |

## License

MIT License. See [LICENSE](LICENSE) for details.
