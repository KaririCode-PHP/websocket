# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [4.0.0] — 2026-11-27

First published release, under the package name kariricode/websocket (the
repository was a placeholder named websocket-handler). The code is the
reviewed 4.0 (kariricode-websocket-v4.0, 2026-03-03), brought to KaririCode
Spec V4.3.3 before publication; the changes below are relative to it.

### Security

- **Unbounded handshake buffer.** The server kept a handshake in memory
  until its blank line arrived, however long it grew. A head is now limited
  to 8192 bytes and refused with 431 (1820, ADR-005).
- **Stream desynchronisation.** The 64-bit length was read with
  `unpack('J')`, so a length with the top bit set became negative, passed
  the size check and threw the parser off the frame boundaries. Such
  lengths are refused (1807), and so are lengths not written in their
  shortest form (1806, ADR-001).
- **One client could stop the server.** An exception from `onOpen` or
  `onMessage` escaped the loop and ended it for every client. It now goes
  to `onError`, and only that connection closes, with 1011 (ADR-002).
- **Client data reflected in responses.** A refused handshake was answered
  with a status line built from the exception message, which repeated the
  client's Origin or Sec-WebSocket-Version. Refusals are now fixed
  responses per status (ADR-005); exception messages are fixed text
  (ADR-003).
- **Repeated handshake fields.** A repeated field replaced the earlier one,
  so a proxy and the server could act on different Host or Origin values.
  Host, Origin, Sec-WebSocket-Key and Sec-WebSocket-Version may now appear
  once (1831); fields without a colon or with control characters are
  refused (1824).
- **Slow consumers.** Output now waits in a per-client buffer instead of
  being written once (see Fixed); a client whose unsent output exceeds
  `maxBufferedOutput` (16 MiB) is disconnected, so it cannot make the
  server hold unlimited memory.

### Fixed

- Frames that arrived in the same read as the handshake were discarded:
  the buffer was emptied after the handshake. They are parsed now.
- A short `fwrite()` lost the rest of the frame, which corrupted the stream
  for that client. Output is written as the socket takes it, across rounds
  (ADR-004).
- The closing handshake (RFC 6455 §7): a client's Close with a code the
  enum did not list, such as 4001 from the application range, was echoed
  and reported as 1000; codes a peer may not send (1005, 1006, 1015), a
  one-byte Close payload and a reason in invalid UTF-8 were accepted; the
  server dropped the TCP
  connection right after its own Close, without waiting for the client's;
  `stop()` cleared the pool without calling `onClose`. Close codes and
  reasons are now validated (1812–1814), the client's code is echoed,
  `onClose` hears the client's code (1005 without one, 1006 when no Close
  arrived), a Close the client does not answer times out after 5 s, and
  `stop()` reports 1001 for every connection.
- `Connection::path()` was always `/`, so routing by path could not work.
  It is the path of the handshake's request target now.
- The handshake accepted a lower-case `get` and request targets with spaces,
  skipped lines without a colon, required no Host, found `Upgrade` in
  `Connection: NotUpgrade` (a substring match) and refused
  `Upgrade: websocket, h2c`. The request line and fields are now parsed
  strictly, Upgrade and Connection are token lists, and Host is required
  (ADR-005).
- Allowed origins compared case by case, so `https://App.example.com` was
  refused for `https://app.example.com`. They now compare without regard to
  case, and a configured origin must be `scheme://host[:port]` (1862).
- UTF-8 was checked with `mb_check_encoding()`, but ext-mbstring was not
  declared. The check now uses PCRE, which every PHP build has.
- `ConnectionPool::broadcast()` caught every `\Throwable`, programming
  errors included; it now skips only a connection that is closing.
- `WebSocketNormalizer::normalize()` relied on `assert()`, which production
  configurations disable; anything other than a connection is refused
  (1870).
- `Integration\TlsContext` imported kariricode/http, which composer.json did
  not require. kariricode/http ^4.0 is a dependency now.
- `CloseCode::tryFromCode()` kept a static map (static state, §18).

### Changed

These break code written against the reviewed 4.0, which was never
published:

- Exceptions: `WebSocketException`, `FrameException` and
  `HandshakeException` (one hierarchy with a close code) are replaced by six
  final classes in kariricode/contract families, with codes 1800–1899 from
  `WsCode` (ADR-003). `FrameException::$closeCode` and
  `HandshakeException::$status` say what the server sent.
- Enums carry their wire values (E-003): `Opcode` and `CloseCode` are backed
  by int (`->value` and `tryFrom()` replace `byteValue()`, `numericCode()`,
  `tryFromByteValue()` and `tryFromCode()`; `CloseCode::isValid()` replaces
  `isSendable()`). `ConnectionState` has Open, Closing and Closed, with
  `canSend()` and `isClosed()`: a connection exists only after its
  handshake.
- `Connection`: `attributes()`, `attribute()` and `putAttribute()` replace
  `metadata()` and `setMetadata()`; `request()`, `subprotocol()` and
  `ping()` are new; `close()` takes `CloseCode|int`.
- `WebSocketConnection` no longer holds a socket; it queues frames that the
  server writes. `writeFrame()`, `socket()`, `disconnect()` and
  `transitionTo()` are gone.
- `WebSocketServer::__construct(MessageHandler, HandshakeNegotiator,
  ServerOptions)`: the limits and timeouts moved to `ServerOptions`.
  `listen()`, `tick()` and `address()` are new.
- `HandshakeNegotiator`: `head()`, `parseRequest(): HandshakeRequest`,
  `negotiate(HandshakeRequest): HandshakeResponse`, `rejection()`;
  `acceptKey()` replaces `computeAcceptKey()`; the constructor takes
  `allowedOrigins`, `subprotocols` and `maxRequestSize`.
- `FrameParser::parse()` returns the frame and the bytes it used.
  `Frame::length()` replaces `payloadLength()`; `Frame::close()` takes
  `CloseCode|int` and validates code and reason; `Frame::continuation()` is
  new.
- `Message` is built by `Message::text()`, which checks UTF-8 (1840), and
  `Message::binary()`; its constructor is private.
- `Server\TlsContext` moved to `Integration\TlsContext`;
  `clientCertificate()` replaces `extractClientCertificate()`, and
  `transportScheme()` is gone.

### Added

- `Server\Session` (one client's protocol state, without a socket),
  `ServerOptions`, `ServerContext` and `Silently` (scoped error handler in
  place of `@`).
- `Frame\ClosePayload`, `Handshake\HandshakeRequest`,
  `Handshake\HandshakeResponse`.
- Limits and timeouts: `maxBufferedOutput`, `closeTimeout`, `readChunk`,
  `maxRequestSize`.
- Configured subprotocols must be tokens (1863).
- A signal that interrupts `stream_select()` makes an empty round, so a
  signal handler can call `stop()`.
- Accepted sockets have TCP_NODELAY, so small frames are not held back by
  Nagle's algorithm.
- Documentation: SPEC-001, ADR-001 to ADR-006, SECURITY.md, CONTRIBUTING.md
  and a quality report.
- Quality gates through KaririCode Devkit (ADR-006), a conformance suite,
  and CI with the actions pinned by commit SHA.

### Removed

- `Handler\AbstractMessageHandler`: implement `Contract\MessageHandler`
  (ADR-002).
- `require-dev`: the toolchain comes from `devkit.php`.

[Unreleased]: https://github.com/KaririCode-PHP/websocket/compare/v4.0.0...HEAD
[4.0.0]: https://github.com/KaririCode-PHP/websocket/releases/tag/v4.0.0
