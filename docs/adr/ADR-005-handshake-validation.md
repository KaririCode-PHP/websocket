# ADR-005 — Handshake Validation and the Origin Policy

**Status:** Accepted
**Date:** 2026-11-27

## Context

The opening handshake is an HTTP/1.1 request a client writes byte by byte.
The reviewed 4.0 waited for its blank line without a size limit, accepted a
lower-case `get` and a request target with spaces, skipped lines without a
colon, let a repeated header overwrite the first, checked no Host header,
matched `Upgrade` in `Connection` as a substring and compared origins case
by case. A refused request got a status line with the exception's message,
which repeated the client's Origin or version.

RFC 6455 §10.2 leaves the Origin check to the server: browsers send the
page's origin, and without a check any site can open a connection with the
user's cookies (cross-site WebSocket hijacking).

## Decision

- `head()` returns the request head once its blank line arrives. A head
  longer than `maxRequestSize` (8192 bytes by default), or an incomplete
  buffer that already is, is refused with 431 (1820); `parseRequest()`
  applies the same limit.
- The request line is exactly `GET <target> HTTP/1.1`: one space between
  parts (1821), a token method that must be `GET` (1822), version HTTP/1.1
  (1823), an origin-form target that starts with `/` and has no control
  characters (1821).
- Each field is `token ":" value` with the RFC 9110 field-value characters;
  surrounding spaces and tabs are trimmed (1824). Host, Origin,
  Sec-WebSocket-Key and Sec-WebSocket-Version may appear once (1831);
  other repeated fields are joined with ", ".
- `negotiate()` checks, in order: Host present (1825); `websocket` in the
  Upgrade token list (1826) and `upgrade` in the Connection token list
  (1827), both case-insensitive; Sec-WebSocket-Version `13`, else 426 with
  `Sec-WebSocket-Version: 13` (1828); a key of 22 base64 characters and
  `==`, which decodes to 16 bytes (1829); the Origin, when origins are
  configured (403, 1830).
- Origins are configured as `scheme://host[:port]` and compared without
  regard to case. With no origins configured, every origin is accepted:
  servers for non-browser clients need that; browser-facing servers must
  list theirs.
- The subprotocol is the first one the client offers that the server
  supports, compared exactly (RFC 6455 §4.2.2); none is selected otherwise.
  Configured subprotocols must be tokens (1863).
- A refusal is a fixed response per status (400, 403, 426, 431) with
  `Connection: close` and `Content-Length: 0`. Nothing from the request is
  written back.

## Consequences

- A handshake larger than the limit cannot grow the buffer; a malformed one
  never reaches the application.
- Firefox's `Connection: keep-alive, Upgrade` is accepted, and
  `Connection: NotUpgrade` is not.
- The application sees only validated fields through `HandshakeRequest`,
  and can read cookies or a token from it in `onOpen`.

## Alternatives Considered

- **Parse the head with PSR-7 (kariricode/http).** Rejected: PSR-7 builds
  messages from a SAPI's parsed request; the handshake arrives as bytes on a
  socket, and the WebSocket rules (single-valued fields, token lists) are
  specific.
- **Refuse requests without Origin.** Rejected: non-browser clients do not
  send one. An allow-list is the browser-side defence.
- **Echo the reason in the response body.** Rejected: E-047; the status
  says enough.

## References

- RFC 6455 §4.1, §4.2 (opening handshake), §10.2 (origin).
- RFC 9110 §5.5 (field values), §5.6.2 (tokens), §7.8 (Upgrade); RFC 9112
  §3 (request line).
- RFC 6454 (origin).
- https://www.rfc-editor.org/rfc/rfc6455#section-4.2
