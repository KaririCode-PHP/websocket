# SPEC-001: WebSocket Architecture Specification

## Version

4.0.0 — 2026-03-03

## Abstract

KaririCode\WebSocket is an RFC 6455 compliant WebSocket server library for PHP 8.4+ with frame parsing/writing, handshake negotiation, connection pool management, message handler abstraction, and Router V4.1 integration.

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                       WebSocketServer                                │
│  start(host, port) → accept loop → handshake → read/write loop      │
│                                                                     │
│  ┌─────────────────┐  ┌──────────────────┐  ┌──────────────────┐  │
│  │ ConnectionPool   │  │ HandshakeNegot.  │  │ MessageHandler    │  │
│  │ add/remove/bcast │  │ RFC 6455 §4     │  │ onOpen/Msg/Close  │  │
│  └─────────────────┘  └──────────────────┘  └──────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│                     Frame Layer (RFC 6455 §5)                        │
│  FrameParser │ FrameWriter │ Frame (VO) │ MessageAssembler           │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│  Connection: WebSocketConnection (state machine)                     │
│  Security: TlsContext (WSS)                                          │
│  Integration: WebSocketNormalizer → Router V4.1                      │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 2. Handshake (RFC 6455 §4)

`HandshakeNegotiator` validates the HTTP Upgrade request:

1. Verify `Upgrade: websocket` and `Connection: Upgrade` headers
2. Validate `Sec-WebSocket-Version: 13`
3. Compute `Sec-WebSocket-Accept` = base64(SHA-1(key + GUID))
4. Optional: validate `Origin` against allowlist
5. Optional: negotiate subprotocol from `Sec-WebSocket-Protocol`
6. Send 101 Switching Protocols response

---

## 3. Frame Protocol (RFC 6455 §5)

### Frame Structure

```
 0                   1                   2                   3
 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
+-+-+-+-+-------+-+-------------+-------------------------------+
|F|R|R|R| opcode|M| Payload len |    Extended payload length    |
|I|S|S|S|  (4)  |A|     (7)     |             (16/64)           |
|N|V|V|V|       |S|             |   (if payload len==126/127)   |
| |1|2|3|       |K|             |                               |
+-+-+-+-+-------+-+-------------+-------------------------------+
|     Masking-key (0 or 4 bytes)                                |
+-------------------------------+-------------------------------+
|                     Payload Data                              |
+---------------------------------------------------------------+
```

### Opcodes

| Opcode | Name | Type |
|---|---|---|
| 0x0 | Continuation | Data |
| 0x1 | Text | Data |
| 0x2 | Binary | Data |
| 0x8 | Close | Control |
| 0x9 | Ping | Control |
| 0xA | Pong | Control |

### Constraints Enforced

- Client→server frames must be masked (configurable)
- Control frames: ≤125 bytes, must have FIN set
- Reserved opcodes (0x3–0x7, 0xB–0xF) rejected
- Maximum payload length enforced (default: 1 MB)
- UTF-8 validation on text frames

---

## 4. Connection State Machine

```
Connecting ──► Open ──► Closing ──► Closed
                 │                    ▲
                 └────────────────────┘
                   (abnormal close)
```

State transitions are validated. Invalid transitions (e.g., Open → Connecting) throw exceptions.

---

## 5. Close Codes (RFC 6455 §7.4)

| Code | Name | Meaning |
|---|---|---|
| 1000 | NormalClosure | Clean close |
| 1001 | GoingAway | Server/client shutting down |
| 1002 | ProtocolError | Protocol violation |
| 1003 | UnsupportedData | Unsupported data type |
| 1006 | AbnormalClosure | No close frame received |
| 1007 | InvalidPayloadData | Payload inconsistency |
| 1008 | PolicyViolation | Generic policy violation |
| 1009 | MessageTooBig | Message exceeds size limit |
| 1011 | InternalError | Unexpected server error |

---

## 6. Message Handler

```php
interface MessageHandler
{
    public function onOpen(Connection $connection): void;
    public function onMessage(Connection $connection, Message $message): void;
    public function onClose(Connection $connection, int $code, string $reason): void;
    public function onError(Connection $connection, \Throwable $error): void;
}
```

`AbstractMessageHandler` provides empty default implementations for optional callbacks.

---

## 7. Router Integration

`WebSocketNormalizer` maps WebSocket connections to Router V4.1:

| Operation | Router Method | Router URI |
|---|---|---|
| Connect | `WS_CONNECT` | Handshake path |

---

## 8. Security

`TlsContext` wraps PHP stream context options for WSS (WebSocket Secure) connections. Supports server certificate, client certificate verification, and cipher configuration.

---

## 9. Error Model

| Exception | Factories |
|---|---|
| `FrameException` | `reservedOpcode()`, `controlFrameTooLarge()`, `fragmentedControlFrame()`, `payloadTooLarge()`, `invalidUtf8()`, `maskRequired()` |
| `HandshakeException` | `missingHeaders()`, `invalidKey()`, `unsupportedVersion()`, `originRejected()` |
| `WebSocketException` | Base exception |

---

## 10. ARFA 1.3 Compliance

| Principle | Implementation |
|---|---|
| P1 (Immutable State) | Frame, Message, SseEvent are `final readonly` |
| P2 (Reactive Flow) | Server event loop: accept → handshake → read → dispatch |
| P4 (Protocol Agnosticism) | WebSocketNormalizer bridges to Router |
