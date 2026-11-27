<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Frame;

/**
 * Serializes {@see Frame} objects into wire-format bytes (RFC 6455 §5.2).
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): output stage of the
 * server's write path — Frame → bytes → socket.
 *
 * Spec V4.0: final class, zero state, pure transformation.
 *
 * Server-to-client frames MUST NOT be masked (RFC 6455 §5.1).
 * Payload length is encoded using the shortest possible representation:
 * 0–125 inline, 126–65535 with 16-bit extension, 65536+ with 64-bit.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5.2 Base Framing Protocol
 */
final class FrameWriter
{
    /**
     * Encodes a Frame into its binary wire representation.
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5.2 Base Framing Protocol
     */
    public function write(Frame $frame): string
    {
        $buffer = '';

        // ── Byte 0: FIN + opcode ─────────────────────────────────────────────
        $byte0 = $frame->opcode->byteValue() & 0x0F;
        if ($frame->fin) {
            $byte0 |= 0x80;
        }
        $buffer .= chr($byte0);

        // ── Byte 1+ : payload length (server frames: mask bit = 0) ───────────
        $length = $frame->payloadLength();

        if ($length <= 125) {
            $buffer .= chr($length);
        } elseif ($length <= 65535) {
            $buffer .= chr(126) . pack('n', $length);
        } else {
            $buffer .= chr(127) . pack('J', $length);
        }

        // ── Payload ──────────────────────────────────────────────────────────
        $buffer .= $frame->payload;

        return $buffer;
    }
}

