# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 4.x | yes |

## Reporting a vulnerability

Report it privately through GitHub's
[security advisories](https://github.com/KaririCode-PHP/websocket/security/advisories/new),
or by e-mail to walmir.silva@kariricode.org. Please do not open a public
issue.

## Threat model

The server reads bytes an untrusted client chose, keeps state for every
client in one process, and writes frames back.

| Threat | Defence |
|---|---|
| Cross-site WebSocket hijacking: a page on another site opens a connection with the user's cookies | `HandshakeNegotiator(allowedOrigins: [...])` refuses other origins with 403 (1830); origins compare without regard to case (ADR-005) |
| Memory exhaustion through a handshake that never ends | the head is limited to `maxRequestSize` (8192 bytes) and refused with 431 (1820) |
| Memory exhaustion through a huge frame or message | the declared length is checked against `maxPayloadSize` before the payload arrives, and fragments against `maxMessageSize`; both fail with 1009 (1805, 1808) |
| A client that stops reading while the server keeps sending | unsent output above `maxBufferedOutput` (16 MiB) disconnects that client; writes never block the loop (ADR-004) |
| A client that never answers the server's Close | disconnected after `closeTimeout` (5 s) |
| Stream desynchronisation through a 64-bit length with the top bit set, or a length in a longer encoding | refused with 1002 (1807, 1806) before anything else is read (ADR-001) |
| Unmasked client frames, reserved bits or opcodes, oversized or fragmented control frames | refused with 1002 (1800–1804) |
| Invalid UTF-8 in text messages or close reasons | refused with 1007 (1811, 1814); checked on the whole message |
| Smuggled handshake fields: a repeated Host, Origin, Key or Version, a field without a colon, control characters | refused with 400 (1824, 1831); the request line must be exactly `GET <target> HTTP/1.1` |
| Reflection of client data in responses or logs | refusals are fixed responses; exception messages are fixed text (E-047) |
| A handler exception taking the server down | caught per callback: `onError`, then Close 1011 (ADR-002) |

Out of scope, by design:

- Authentication and authorisation: the application reads cookies or a
  token from `HandshakeRequest` in `onOpen` and closes the connection when
  they fail.
- Rate limits and limits on the number of connections: a reverse proxy or
  the application sets them.
- TLS: a reverse proxy terminates it, or the application's own loop with
  `Integration\TlsContext`.
- permessage-deflate and other extensions: not implemented, so reserved
  bits must be zero.

## ASVS 5.0, level 1

The relevant requirements are WebSocket origin validation, input
validation at the protocol boundary (handshake fields, frame headers,
UTF-8), resource limits against denial of service, and error messages
that do not reflect input; the threat model above maps them. The package
stores no credentials and keeps no sessions beyond open connections.
