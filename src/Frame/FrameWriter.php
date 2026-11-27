<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Frame;

/**
 * Turns a frame into the bytes a server sends (RFC 6455 §5.2): FIN and
 * opcode, then the payload length in its shortest form (7 bits up to 125,
 * 16 bits up to 65535, 64 bits beyond), then the payload. Server frames
 * are never masked (§5.1), whatever the frame's masked flag says.
 *
 * ARFA 1.3: P2 (Frame → bytes, the last stage of the write path).
 * Spec V4.3.3: final readonly, pure function.
 *
 * Preconditions: none; Frame already enforces the control-frame rules.
 *
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $bytes = (new FrameWriter())->write(Frame::text('{"event":"update"}'));
 *     // "\x81\x12{\"event\":\"update\"}"
 *
 * References: RFC 6455 §5.1, §5.2; ADR-001.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-5.2
 */
final readonly class FrameWriter
{
    public function write(Frame $frame): string
    {
        $length = $frame->length();
        $length = match (true) {
            $length <= 125 => chr($length),
            $length <= 0xFFFF => chr(126) . pack('n', $length),
            default => chr(127) . pack('J', $length),
        };

        return chr(($frame->fin ? 0x80 : 0) | $frame->opcode->value) . $length . $frame->payload;
    }
}
