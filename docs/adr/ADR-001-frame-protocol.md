# ADR-001: RFC 6455 Frame Protocol Implementation

## Status

Accepted — V4.0.0

## Date

2026-03-03

## Context

WebSocket communication uses a binary frame protocol defined in RFC 6455 §5. Each frame carries an opcode (text, binary, close, ping, pong), masking key (client→server only), and variable-length payload. A robust implementation must handle:

- Extended payload lengths (7-bit, 16-bit, 64-bit)
- Client-to-server masking enforcement (RFC 6455 §5.1)
- Control frame constraints (≤125 bytes, no fragmentation)
- Message fragmentation and reassembly
- UTF-8 validation for text frames

### Prior Art

- **Ratchet/RFC6455** — mature PHP frame parser, but coupled to Ratchet's event loop.
- **Textalk/websocket** — simple client, no server-side frame parsing.
- **Swoole** — C-extension with built-in WebSocket, not portable.

## Decision

Implement a standalone `FrameParser` and `FrameWriter` pair that operate on raw byte buffers:

### FrameParser

Reads a byte buffer and returns a `Frame` value object (or null if incomplete). Enforces:

- Mask requirement (configurable, default: required for client→server)
- Maximum payload length (configurable, default: 1 MB)
- Control frame constraints (≤125 bytes, must be FIN)
- Reserved opcode rejection
- UTF-8 validation for text frames (opcode 0x1)

### FrameWriter

Serializes a `Frame` to bytes with optional masking. Produces the correct extended payload length encoding based on payload size.

### XOR Masking (RFC 6455 §5.3)

The `applyMask()` function XORs payload bytes against a 4-byte masking key. XOR is self-inverse, so the same function masks and unmasks. The implementation uses byte-level iteration (not `str_repeat` + XOR) for correctness on arbitrary-length payloads.

### Message Assembly

`MessageAssembler` reassembles fragmented messages from continuation frames (opcode 0x0) until a FIN frame arrives. It tracks the original opcode (text or binary) and concatenates payloads.

## Consequences

### Positive

- Standalone parser works with any I/O layer (stream_socket, Swoole, ReactPHP).
- Configurable constraints allow tuning for different use cases (small IoT frames vs. large binary transfers).
- Frame-level API enables custom protocols on top of WebSocket (subprotocol negotiation).

### Negative

- Byte-level parsing in PHP is slower than C extensions. For extreme throughput, Swoole's built-in parser is faster.
- Memory allocation for large payloads is a concern — streaming frame parsing is a future enhancement.

## References

- RFC 6455 §5: https://datatracker.ietf.org/doc/html/rfc6455#section-5
- ARFA 1.3 — P1 (Immutable State): Frame is `final readonly`
