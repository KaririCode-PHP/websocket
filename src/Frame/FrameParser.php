<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Frame;

use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\FrameException;

/**
 * Parses raw bytes into {@see Frame} value objects per RFC 6455 §5.
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): first stage of the server's
 * data pipeline — raw bytes → Frame → {@see MessageAssembler} → handler.
 * Each stage consumes and produces immutable value objects.
 *
 * Spec V4.0: final class, zero state between parse() calls. Configurable
 * payload limit and masking enforcement via constructor parameters.
 *
 * Wire format (RFC 6455 §5.2):
 *   0                   1                   2                   3
 *   0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
 *  +-+-+-+-+-------+-+-------------+-------------------------------+
 *  |F|R|R|R| opcode|M| Payload len |    Extended payload length    |
 *  |I|S|S|S|  (4)  |A|     (7)     |             (16/64)           |
 *  |N|V|V|V|       |S|             |   (if payload len==126/127)   |
 *  | |1|2|3|       |K|             |                               |
 *  +-+-+-+-+-------+-+-------------+-------------------------------+
 *  |     Masking-key (0 or 4 bytes)                                |
 *  +-------------------------------+-------------------------------+
 *  |     Payload Data (x bytes)                                    |
 *  +---------------------------------------------------------------+
 *
 * Enforces client-to-server masking requirement (RFC 6455 §5.1).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5.2 Base Framing Protocol
 */
final class FrameParser
{
    // RFC 6455 §5.2: extended payload length thresholds
    private const PAYLOAD_LENGTH_16BIT = 126;
    private const PAYLOAD_LENGTH_64BIT = 127;

    public function __construct(
        private readonly int $maxPayloadSize = 1_048_576,
        private readonly bool $requireMask = true,
    ) {
    }

    /**
     * Parses a single frame from a byte buffer.
     *
     * Returns [Frame, bytesConsumed] on success, or null if the buffer
     * does not yet contain a complete frame (wait for more data).
     *
     * @return array{Frame, int}|null
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5.2 Base Framing Protocol
     */
    public function parse(string $buffer): ?array
    {
        $bufferLength = strlen($buffer);
        $offset = 0;

        // Minimum frame: 2 bytes header
        if ($bufferLength < 2) {
            return null;
        }

        // ── Byte 0: FIN + RSV + Opcode ───────────────────────────────────────
        $byte0 = ord($buffer[0]);
        $fin = ($byte0 & 0x80) !== 0;
        $rsv = ($byte0 >> 4) & 0x07;
        $opcodeValue = $byte0 & 0x0F;

        // RSV bits MUST be 0 unless an extension is negotiated
        if ($rsv !== 0) {
            throw new FrameException(
                CloseCode::ProtocolError,
                "Non-zero RSV bits without negotiated extension: {$rsv}",
            );
        }

        $opcode = Opcode::tryFromByteValue($opcodeValue);
        if ($opcode === null) {
            throw FrameException::reservedOpcode($opcodeValue);
        }

        // ── Byte 1: MASK + Payload Length ────────────────────────────────────
        $byte1 = ord($buffer[1]);
        $masked = ($byte1 & 0x80) !== 0;
        $payloadLength = $byte1 & 0x7F;
        $offset = 2;

        if ($this->requireMask && !$masked) {
            throw FrameException::maskRequired();
        }

        // Extended payload length
        if ($payloadLength === self::PAYLOAD_LENGTH_16BIT) {
            if ($bufferLength < $offset + 2) {
                return null;
            }
            $payloadLength = unpack('n', substr($buffer, $offset, 2))[1];
            $offset += 2;
        } elseif ($payloadLength === self::PAYLOAD_LENGTH_64BIT) {
            if ($bufferLength < $offset + 8) {
                return null;
            }
            $payloadLength = unpack('J', substr($buffer, $offset, 8))[1];
            $offset += 8;
        }

        // ── Validate payload size ────────────────────────────────────────────
        if ($payloadLength > $this->maxPayloadSize) {
            throw FrameException::payloadTooLarge($payloadLength, $this->maxPayloadSize);
        }

        // Control frames: max 125 bytes, no fragmentation
        if ($opcode->isControl()) {
            if ($payloadLength > 125) {
                throw FrameException::controlFrameTooLarge($payloadLength);
            }
            if (!$fin) {
                throw FrameException::fragmentedControlFrame();
            }
        }

        // ── Masking key (4 bytes if masked) ──────────────────────────────────
        $maskingKey = '';
        if ($masked) {
            if ($bufferLength < $offset + 4) {
                return null;
            }
            $maskingKey = substr($buffer, $offset, 4);
            $offset += 4;
        }

        // ── Payload ──────────────────────────────────────────────────────────
        if ($bufferLength < $offset + $payloadLength) {
            return null;
        }

        $payload = substr($buffer, $offset, $payloadLength);
        $offset += $payloadLength;

        // Unmask payload (RFC 6455 §5.3)
        if ($masked && $payloadLength > 0) {
            $payload = self::applyMask($payload, $maskingKey);
        }

        $frame = new Frame($fin, $opcode, $payload, $masked);

        return [$frame, $offset];
    }

    /**
     * XOR masking/unmasking (RFC 6455 §5.3).
     * Same operation applies and removes the mask (XOR is self-inverse).
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5.2 Base Framing Protocol
     */
    public static function applyMask(string $data, string $maskingKey): string
    {
        $length = strlen($data);
        $result = $data;

        for ($i = 0; $i < $length; $i++) {
            $result[$i] = $data[$i] ^ $maskingKey[$i % 4];
        }

        return $result;
    }
}

