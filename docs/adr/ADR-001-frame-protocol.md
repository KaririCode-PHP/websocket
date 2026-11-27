# ADR-001 — Frame Protocol: Strict Parsing, Shortest Encoding, Bounded Messages

**Status:** Accepted
**Date:** 2026-11-27 (first written 2026-03-03)

## Context

RFC 6455 §5 frames every message: two header bytes (FIN, three reserved
bits, opcode, mask bit, a 7-bit length), an optional 16- or 64-bit length, a
4-byte masking key on client frames, then the payload. A server parses bytes
an untrusted client chose, so each rule of §5 is also a defence.

The reviewed 4.0 (kariricode-websocket-v4.0, 2026-03-03) read the 64-bit
length with `unpack('J')`: a length with the top bit set came out negative,
passed the size check and desynchronised the stream. It accepted lengths in
a longer encoding than needed, unmasked the payload byte by byte in a PHP
loop, checked UTF-8 with `mb_check_encoding()` without declaring
ext-mbstring, and turned any Close payload into a code and reason without
checking either.

## Decision

- `FrameParser::parse(string $buffer)` returns the frame and the number of
  bytes it used, or null while the frame is incomplete; the caller keeps
  the rest of the buffer, so frames that arrive together are all read.
- Each header rule is checked as soon as its bytes are there, before the
  payload: reserved bits zero (no extension is negotiated, 1801), known
  opcode (1800), mask set on client frames (1802), control frames final
  (1804) and at most 125 bytes (1803), a 16-bit length of at least 126 and a
  64-bit length of at least 65536 (1806) with the top bit clear (1807). All
  of these fail the connection with 1002.
- The payload limit (`maxPayloadSize`, 1 MiB by default) is compared with
  the declared length, so a frame above it fails with 1009 (1805) before any
  of its payload is buffered.
- Unmasking XORs the payload with the key padded to its length
  (`str_pad('', strlen($data), $key)`); the same call masks.
- `FrameWriter` writes server frames unmasked (§5.1) with the shortest
  length encoding.
- `Frame` is a final readonly value. Its constructor refuses a control frame
  that is not final or carries more than 125 bytes (1844), and
  `Frame::close()` takes only codes an endpoint may send (1000–1003,
  1007–1014, 3000–4999; 1843) and a UTF-8 reason of at most 123 bytes
  (1841, 1842).
- `MessageAssembler` joins fragments: a continuation without a message
  (1809) and a new message inside one (1810) fail with 1002; the limit
  (`maxMessageSize`, 10 MiB) counts every fragment and drops the partial
  message (1808, close code 1009); UTF-8 is checked once on the whole text
  message, because a fragment may end inside a sequence (1811, close code
  1007). Control frames never enter it (1845).
- `ClosePayload::parse()` reads a received Close: an empty payload has no
  code (reported as 1005), one byte is a protocol error (1812), the code
  must be one a peer may send (1813) and the reason UTF-8 (1814, close code
  1007).
- `FrameException` carries the close code its rule demands (§7.4.1), so
  the session closes with the right one without a table of its own.

## Consequences

- A malformed header fails the connection with the close code RFC 6455
  names; nothing reads past it.
- A client cannot make the server hold more than `maxPayloadSize` for one
  frame or `maxMessageSize` for one message.
- Messages are held whole until their last fragment; streaming a message to
  the handler is not supported.
- permessage-deflate (RFC 7692) is not supported: reserved bits must be
  zero.

## Alternatives Considered

- **Per-byte unmasking (the reviewed code).** Rejected: a PHP loop per byte;
  string XOR runs in C.
- **UTF-8 checked per fragment.** Rejected: §5.6 lets a fragment end inside
  a code point; only the whole message has to be valid.
- **`mb_check_encoding()`.** Rejected: it needs ext-mbstring;
  `preg_match('//u')` uses PCRE, which every PHP build has, and refuses
  overlong forms and surrogates as RFC 3629 does.
- **permessage-deflate now.** Deferred: it adds a negotiation and needs
  limits on the inflated size.

## References

- RFC 6455 §5.1–§5.8 (framing), §7.4 (status codes), §8.1 (UTF-8), §10.4
  (limits).
- RFC 3629 (UTF-8).
- https://www.rfc-editor.org/rfc/rfc6455#section-5
