# ADR-002 — Connections, Handlers, the Closing Handshake and Routing

**Status:** Accepted
**Date:** 2026-11-27 (first written 2026-03-03)

## Context

An application sees a WebSocket server through two things: the connection
it talks to and the callbacks it receives. In the reviewed 4.0 the
connection held the socket and wrote frames with one `fwrite()`, so a
partial write lost the rest of the frame. Metadata had a setter
(`setMetadata()`), state changed through a public `transitionTo()`, the
path was always `/`, and applications extended a non-final
`AbstractMessageHandler`. A client's Close removed the connection without
the closing handshake RFC 6455 §7 describes. `ConnectionPool::broadcast()`
caught every `\Throwable`, programming errors included.

## Decision

- `Contract\Connection` is what a handler sees: id, state, remote address,
  the handshake request, its path (without the query) and the selected
  subprotocol; `send()`, `ping()`, `close()`; and attributes the
  application attaches (`attribute()`, `putAttribute()`, `attributes()`).
  It has no socket.
- `WebSocketConnection` queues frames in an outbox that the server writes
  (ADR-004). `send()` and `ping()` on a connection that is not open throw
  1850. `close()` validates its code and reason even when the connection is
  already closing, queues one Close and moves to Closing; later calls queue
  nothing.
- `Contract\MessageHandler` has four callbacks: `onOpen`, `onMessage`,
  `onClose(connection, code, reason)` and `onError`. There is no base class.
- Closing handshake (§7.1): when the client sends Close, the server answers
  with the client's code (an empty Close for an empty one), reports
  `onClose` with that code and reason (1005 without a code) and closes the
  TCP connection once the answer is written, as §7.1.1 asks of a server.
  After the server's own `close()`, data frames are discarded (§1.4), the
  client's Close ends the connection, and a client that does not answer in
  `closeTimeout` (5 s) is disconnected and reported as 1006.
- Failing the connection (§7.1.7): a protocol error goes to `onError`, the
  server sends Close with the rule's code (1002, 1007 or 1009) and closes
  the socket; `onClose` hears that code.
- An exception from `onOpen` or `onMessage` goes to `onError`, and the
  connection closes with 1011. An exception from `onError` is dropped; one
  from `onClose` goes to `onError`. The loop never stops for a handler.
- `onClose` runs exactly once for every connection that opened; a dropped
  TCP connection is reported as 1006.
- `ConnectionPool` keeps connections by id. `broadcast()` queues a message
  on every open connection the filter accepts and skips one that started
  closing during the broadcast (1850); other exceptions propagate.
- Routing: `Integration\WebSocketNormalizer` turns a connection into the
  router's `NormalizedRequest` with the pseudo-method `WEBSOCKET` and the
  connection's path, so kariricode/router chooses a handler and extracts
  path parameters (`/chat/{room}`). Only `src/Integration` imports the
  router.

## Consequences

- Handlers never see sockets or partial writes, and cannot block the loop
  on a slow client.
- The close code an application reads follows RFC 6455 §7.1.5: the peer's
  code, 1005 for none, 1006 when no Close arrived.
- Applications implement an interface of four methods instead of extending
  a class; the conformance suite keeps every class final.

## Alternatives Considered

- **Keep `AbstractMessageHandler`.** Rejected: Spec V4.3.3 keeps classes
  final, and empty defaults hide a missing `onError`.
- **Connections that write to their socket (the reviewed design).**
  Rejected: a handler would block on a slow client, and partial writes need
  a buffer the loop owns anyway.
- **Report the server's own code when the client never answers its
  Close.** Rejected: §7.1.5 defines the close code as 1006 when no Close
  frame was received.
- **Catch every `\Throwable` in `broadcast()`.** Rejected: only a
  connection that is closing is an expected failure.

## References

- RFC 6455 §1.4, §7.1 (closing), §7.4 (status codes).
- kariricode/router `RequestNormalizer` and `NormalizedRequest` (its
  ADR-002).
- https://www.rfc-editor.org/rfc/rfc6455#section-7
