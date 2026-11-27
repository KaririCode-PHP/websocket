# SPEC-001 — kariricode/websocket Architecture

**Version:** 4.0.0 · **Spec:** KaririCode V4.3.3 · **ARFA:** 1.3, level 1 · **ASVS:** 5.0, level 1
**Date:** 2026-11-27 (first written 2026-03-03)

## 1. Scope

kariricode/websocket is an RFC 6455 server for PHP 8.4: the opening
handshake, frames and messages, the closing handshake, a connection pool, a
single-process event loop on `stream_select()`, and the bridge that lets
kariricode/router choose a handler by path. Every rule of RFC 6455 that a
client can break is checked, and each failure has its own code (ADR-003).
It is not a client, and it does not implement extensions
(permessage-deflate) or TLS in its loop (§11).

Dependencies: `kariricode/exception` ^2.0.2 (family bases, code table),
`kariricode/http` ^4.0 (TLS settings and client certificates, imported only
by `src/Integration`), `kariricode/router` ^4.1 (imported only by
`src/Integration`). ext-openssl is suggested for `Integration\TlsContext`.

## 2. Types

| Type | Kind | Role |
|---|---|---|
| `Frame\Frame` | final readonly | one frame: FIN, opcode, payload, mask flag (§4) |
| `Frame\FrameParser` | final readonly | bytes → frame and bytes used; every header rule (ADR-001) |
| `Frame\FrameWriter` | final readonly | frame → bytes, unmasked, shortest length |
| `Frame\MessageAssembler` | final | fragments → message; limit and UTF-8 (§5) |
| `Frame\ClosePayload` | final readonly | a received Close: code and reason, checked |
| `Message\Message` | final readonly | a whole text or binary message |
| `Handshake\HandshakeNegotiator` | final readonly | head, request, 101 response, rejections (§3) |
| `Handshake\HandshakeRequest` | final readonly | target and fields of the accepted request |
| `Handshake\HandshakeResponse` | final readonly | the 101 head and the selected subprotocol |
| `Contract\Connection` | interface | what a handler sees and does (§6) |
| `Contract\MessageHandler` | interface | `onOpen`, `onMessage`, `onClose`, `onError` |
| `Connection\WebSocketConnection` | final | a connection with an outbox |
| `Connection\ConnectionPool` | final, `Countable` | connections by id; broadcast |
| `Server\WebSocketServer` | final | sockets and the loop (§8) |
| `Server\Session` | final | one client's protocol state, without a socket |
| `Server\ServerOptions` | final readonly | limits and timeouts (§9) |
| `Server\ServerContext` | final readonly | handler, negotiator, pool, options shared by sessions |
| `Server\Silently` | final, static | runs a socket call with warnings turned into return values |
| `Integration\WebSocketNormalizer` | final | router `RequestNormalizer` for connections (§10) |
| `Integration\TlsContext` | final, static | stream context and client certificate for wss:// (§11) |
| `Enum\Opcode` | backed enum | the six opcodes; `isControl()` |
| `Enum\CloseCode` | backed enum | registered close codes, reasons, `isValid()` |
| `Enum\ConnectionState` | enum | Open, Closing, Closed |
| `Exception\WsCode` | enum, `ErrorCode` | codes 1800–1899, prefix WS (ADR-003) |
| `Exception\FrameException` | final, Protocol | 1800–1814, with the close code |
| `Exception\HandshakeException` | final, Protocol | 1820–1831, with the HTTP status |
| `Exception\InvalidMessageException` | final, Validation | 1840–1845 |
| `Exception\ConnectionException` | final, Runtime | 1850–1851 |
| `Exception\InvalidConfigurationException` | final, Configuration | 1860–1863 |
| `Exception\UnsupportedRequestException` | final, Validation | 1870 |

## 3. Opening handshake

`HandshakeNegotiator::head()` returns the head once `CRLF CRLF` arrives;
a head (or an incomplete buffer) above `maxRequestSize` (8192) is refused
with 431. `parseRequest()` requires `GET <origin-form target> HTTP/1.1` with
single spaces, token field names and RFC 9110 field values, and refuses a
repeated Host, Origin, Sec-WebSocket-Key or Sec-WebSocket-Version.
`negotiate()` checks Host, the Upgrade and Connection token lists, version
13 (426 with `Sec-WebSocket-Version: 13` otherwise), the key format, and
the Origin against `allowedOrigins` (case-insensitive; empty accepts every
origin). The response carries `Sec-WebSocket-Accept` =
base64(SHA-1(key . GUID)) and, when the client offered one the server
supports, the first such subprotocol. Refusals are fixed responses with
`Connection: close` (ADR-005).

## 4. Frames

`FrameParser` (server: `requireMask` true) refuses, with close code 1002:
non-zero RSV bits, reserved opcodes, unmasked frames, fragmented or
oversized (> 125) control frames, non-minimal and out-of-range lengths. A
declared length above `maxPayloadSize` fails with 1009 before the payload
arrives. `FrameWriter` writes `FIN|opcode`, the length (7 bits, 126 + 16
bits, or 127 + 64 bits) and the payload, never masked. `Frame::close()`
accepts codes 1000–1003, 1007–1014 and 3000–4999 and a UTF-8 reason of at
most 123 bytes (ADR-001).

## 5. Messages

`MessageAssembler::feed()` returns a `Message` on the final fragment and
null before. A continuation outside a message, or a new data frame inside
one, is a protocol error (1002). The sum of the fragments may not exceed
`maxMessageSize` (1009). Text is checked as UTF-8 on the whole message
(1007). `Message::text()` refuses invalid UTF-8 from the application.

## 6. Connections and handlers

After a successful handshake the session creates a `WebSocketConnection`
with a random 16-hex-digit id, the client's address, the request and the
subprotocol, adds it to the pool and calls `onOpen`. Frames that arrived
with the handshake are parsed at once. Pings are answered with a pong
carrying the same payload; pongs are ignored. Data frames go through the
assembler to `onMessage`. `send()` and `ping()` work only while Open
(1850); `close()` queues one Close and moves to Closing. Attributes hold
application data per connection. An exception from `onOpen` or `onMessage`
reaches `onError` and closes the connection with 1011; `onClose` runs once
per opened connection (ADR-002).

## 7. Closing

| Situation | Server sends | `onClose` code | TCP |
|---|---|---|---|
| Client Close with a code | Close with the same code | the client's code and reason | closed by the server after the echo |
| Client Close without a code | empty Close | 1005 | closed by the server after the echo |
| Invalid Close payload | Close 1002 or 1007 | 1002 or 1007 | closed |
| Server `close($code)`, client answers | Close `$code` | the client's code | closed after the answer |
| Server `close($code)`, no answer in `closeTimeout` | Close `$code` | 1006 | closed at the timeout |
| Protocol error | Close 1002, 1007 or 1009 | the same code | closed |
| Handler exception | Close 1011 | the client's answer, or 1006 | closed |
| `WebSocketServer::stop()` | Close 1001 | 1001 | closed |
| TCP lost or reset | nothing | 1006 | closed |

## 8. The loop

Each `tick()`: `stream_select()` over the listening socket and every
client (writers only when output is pending), for at most `selectTimeout`;
accept with a zero timeout; read up to `readChunk` bytes per readable
client; write as much pending output as each writable socket takes; then
drop sessions that are over (output written), that exceed
`maxBufferedOutput`, or whose Close went unanswered for `closeTimeout`. An
interrupted `select` is an empty round. `start()` loops until `stop()`;
`tick()` without a listening socket ends the loop (ADR-004).

## 9. Limits and options

| Option | Default | Where |
|---|---|---|
| `HandshakeNegotiator::$maxRequestSize` | 8192 bytes | handshake head |
| `ServerOptions::$maxPayloadSize` | 1 MiB | one frame |
| `ServerOptions::$maxMessageSize` | 10 MiB | one message, all fragments |
| `ServerOptions::$maxBufferedOutput` | 16 MiB | unsent output per client |
| `ServerOptions::$selectTimeout` | 0.2 s | longest wait of one round |
| `ServerOptions::$closeTimeout` | 5 s | wait for the client's Close |
| `ServerOptions::$readChunk` | 64 KiB | bytes read per client per round |

Sizes must be at least 1 (1860) and timeouts zero or more (1861).

## 10. Router integration

`WebSocketNormalizer::normalize($connection)` returns
`NormalizedRequest('WEBSOCKET', $connection->path(), $connection)`, so
routes registered for the method `WEBSOCKET` match connections by path and
expose path parameters. Anything other than a `Connection` is refused
(1870).

## 11. TLS

The loop speaks plain TCP. TLS terminates in a reverse proxy, or in a
caller's own loop: `TlsContext::create(TlsConfiguration)` builds a stream
context from kariricode/http's settings with TLS compression disabled, and
`TlsContext::clientCertificate($stream)` reads the certificate PHP captured
when the client presented one.

## 12. ARFA 1.3

| Principle | Where |
|---|---|
| P1 immutable state | frames, messages, handshake values, options |
| P2 reactive flow | bytes → frames → messages → handler; output queued per connection |
| P4 protocol agnosticism | `WebSocketNormalizer` keeps the router free of WebSocket types |
| P5 observability | codes in every exception (1800–1899); close codes on the wire |
