# ADR-003 — Exception Families and the Code Block 1800–1899

**Status:** Accepted
**Date:** 2026-11-27

## Context

The reviewed 4.0 had an abstract `WebSocketException` with a close code and
two subclasses. Messages repeated client data ("Origin rejected: …",
"Unsupported WebSocket version: …"), and the refused handshake sent that
message back in the HTTP status line. KaririCode Spec V4.3.3 §8.4
identifies a failure by its kariricode/contract family plus a four-digit
code from a block the ecosystem registry (kariricode/exception,
CODE-BLOCKS.md) gives one package; E-047 forbids repeating input in
messages.

## Decision

- The registry gives kariricode/websocket block 1800–1899 under the prefix
  WS. kariricode/exception 2.0.2 lists the block, so the constraint is
  `^2.0.2`.
- `WsCode` (an `ErrorCode` enum) holds the codes:

  | Range | Area | Class (family) |
  |---|---|---|
  | 1800–1819 | frames and messages from the peer | `FrameException` (Protocol), with the close code to send |
  | 1820–1839 | the opening handshake | `HandshakeException` (Protocol), with the HTTP status to answer |
  | 1840–1849 | frames and messages the application builds | `InvalidMessageException` (Validation) |
  | 1850–1859 | connection and socket state | `ConnectionException` (Runtime) |
  | 1860–1869 | configuration | `InvalidConfigurationException` (Configuration) |
  | 1870–1879 | integration | `UnsupportedRequestException` (Validation) |

- Six final classes on the kariricode/exception bases, each built by named
  constructors only, one per code. `FrameException` carries a `CloseCode`
  (ADR-001); `HandshakeException` carries the HTTP status (400, 403, 426 or
  431, ADR-005).
- Messages are fixed text. The few values in them come from the package or
  the application, never from a client: a parameter name, an allowed origin
  or subprotocol from the configuration, the bind address, one of the four
  single-valued header names, a close code the application tried to send.
  Strings go through `quote()`.
- A refused handshake is answered from a fixed table of statuses
  (`HandshakeNegotiator::rejection()`); no exception message reaches the
  client.

## Consequences

- Logs and handlers can branch on `$e->errorCode` (a `WsCode`) and on the
  family; `FrameException::$closeCode` and `HandshakeException::$status`
  say what went on the wire.
- Codes are stable API: a code is never reused or renumbered, and a new one
  takes the next free number in its range.
- `CODE-BLOCKS.md` in kariricode/exception records the block; the
  conformance suite checks the ranges against it.

## Alternatives Considered

- **One exception class with a code.** Rejected: families let callers
  catch protocol errors apart from their own mistakes.
- **Close codes as the error codes.** Rejected: one close code (1002)
  covers more than ten different rules; the WS codes tell them apart.
- **Messages with the offending value.** Rejected: E-047; the value is in
  the request the application already has.

## References

- KaririCode Spec V4.3.3 §8.4, E-047; kariricode/exception CODE-BLOCKS.md.
- RFC 6455 §7.4 (status codes); RFC 9110 §15 (status codes).
- https://www.rfc-editor.org/rfc/rfc6455#section-7.4
