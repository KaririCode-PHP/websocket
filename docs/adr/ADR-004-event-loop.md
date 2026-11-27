# ADR-004 — The Event Loop: Sessions without Sockets, Buffered Output, Limits and Timeouts

**Status:** Accepted
**Date:** 2026-11-27

## Context

The reviewed 4.0 put the whole server in one class. It found the
connection for a readable socket by walking the pool, waited for the end of
the handshake without a size limit, emptied the read buffer after the
handshake (frames sent with it were lost), wrote with one `fwrite()` whose
short count it ignored, silenced socket calls with `@`, and let an
exception from `onMessage` escape the loop and stop the server.

## Decision

- `Server\Session` holds one client's protocol state and no socket: bytes
  in (`receive()`), bytes out (`pending()`, `written()`), the handshake,
  the frame parser and the message assembler. It is tested without
  sockets.
- `WebSocketServer` owns the sockets. Each round of `tick()` waits in
  `stream_select()` for up to `selectTimeout` (0.2 s), accepts new clients,
  reads up to `readChunk` (64 KiB) from each readable socket, writes as much
  pending output as each writable socket takes, and closes what is over.
  Clients are kept in a map by resource id. Accepted sockets have
  TCP_NODELAY: frames are written whole, so Nagle's algorithm would only
  delay them.
- Output is buffered per session; a short write keeps the rest for the next
  round, so no frame is lost or interleaved. A client whose unsent output
  exceeds `maxBufferedOutput` (16 MiB) is disconnected and reported as 1006:
  a client that stops reading cannot make the server hold unlimited memory,
  nor delay the others.
- After the handshake, the bytes left in the buffer are parsed as frames
  right away.
- A client that does not answer the server's Close within `closeTimeout`
  (5 s) is disconnected.
- Socket calls run through `Silently::run()`, which scopes an error handler
  instead of using `@`; the code acts on the return value. A signal that
  interrupts `stream_select()` makes an empty round, so a signal handler can
  call `stop()` (with `pcntl_async_signals(true)`).
- `stop()` sends Going Away (1001) to every open connection, writes what
  each socket takes at once, closes every socket and ends the loop of
  `start()`. `listen()` and `tick()` let a caller drive the loop.
- `ServerOptions` holds the limits and timeouts and validates them (1860,
  1861).

## Consequences

- Every protocol rule is tested against a session, and the loop against
  real loopback sockets.
- One process, one thread: handlers must return quickly. `stream_select()`
  handles descriptors below `FD_SETSIZE` (1024 on common Linux builds);
  beyond a few hundred connections per process, an event-loop extension or
  another runtime fits better.
- The loop speaks plain TCP. TLS (wss://) terminates in a reverse proxy, or
  in a caller's own loop built on `Integration\TlsContext`.

## Alternatives Considered

- **Keep one server class.** Rejected: the protocol could only be tested
  through sockets.
- **Write synchronously in `send()`.** Rejected: a slow client would block
  every other client.
- **An event-loop library dependency (ReactPHP, Amp).** Rejected for 4.0:
  the package stays dependency-free beyond the ecosystem; `Session` keeps
  the protocol independent of the loop, so another loop can drive it.
- **TLS in the loop.** Deferred: non-blocking TLS handshakes need their own
  state machine.

## References

- RFC 6455 §4.2, §7.1.1 (the server closes the TCP connection first),
  §10.4 (limits).
- PHP manual, `stream_select()`, `stream_socket_server()`,
  `pcntl_async_signals()`.
- https://www.php.net/manual/en/function.stream-select.php
