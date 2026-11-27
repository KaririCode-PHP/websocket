# ADR-002: Connection Pool and State Machine

## Status

Accepted — V4.0.0

## Date

2026-03-03

## Context

A WebSocket server manages multiple concurrent connections, each with its own lifecycle state. The server must track connections for broadcasting, enforce connection limits, and handle graceful shutdown.

### Connection States (RFC 6455 §7.1)

```
Connecting → Open → Closing → Closed
```

Each state transition has constraints: only Open connections accept data frames, only Open or Connecting can transition to Closing, and Closed connections must be removed from the pool.

## Decision

### ConnectionState Enum

```php
enum ConnectionState: string
{
    case Connecting = 'connecting';
    case Open = 'open';
    case Closing = 'closing';
    case Closed = 'closed';
}
```

### WebSocketConnection

Each connection wraps a socket resource with state tracking, frame I/O delegation, and metadata (remote address, connected-at timestamp). State transitions are validated — invalid transitions throw exceptions.

### ConnectionPool

Thread-safe (within a single process) connection registry with:

- `add(connection)` — register a new connection
- `remove(id)` — deregister on close
- `get(id)` — retrieve by identifier
- `broadcast(Frame)` — send to all Open connections
- `count()` — current pool size
- `all()` — iterate all connections

### Close Handshake (RFC 6455 §7.1.1)

The close handshake requires both sides to exchange Close frames:

1. Initiator sends Close frame with status code
2. Responder echoes Close frame
3. Both sides transition to Closed
4. TCP connection is terminated

`CloseCode` enum carries the 11 standard close codes (1000–1015) with human-readable descriptions.

## Consequences

### Positive

- State machine prevents invalid operations (e.g., writing to a Closing connection).
- Pool enables server-level operations: broadcast, graceful shutdown, connection counting.
- Enum-based state is serializable for monitoring/observability.

### Negative

- Connection pool is in-memory — horizontal scaling requires external pub/sub (Redis, Kafka).
- No built-in connection limit enforcement — callers must check `pool->count()` before accepting.

## References

- RFC 6455 §7: https://datatracker.ietf.org/doc/html/rfc6455#section-7
- ARFA 1.3 — P1 (Immutable State): ConnectionState is a backed string enum
