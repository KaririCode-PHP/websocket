<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Frame;

use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\FrameException;
use KaririCode\WebSocket\Exception\InvalidConfigurationException;

/**
 * Reads one frame from the front of a byte buffer (RFC 6455 §5.2) and
 * says how many bytes it used, or null when the frame is not complete
 * yet. Every header rule is checked as soon as its bytes are there, before
 * the payload arrives: reserved bits and opcodes, the client mask, control
 * frames that are fragmented or longer than 125 bytes, lengths that are
 * not minimal or have the top bit set, and the payload limit. A client
 * therefore cannot make the server buffer more than the limit it set.
 *
 * ARFA 1.3: P2 (bytes → Frame, the first stage of the read path).
 * Spec V4.3.3: final readonly, no state between calls.
 *
 * Preconditions: $maxPayloadSize ≥ 1 (InvalidConfigurationException 1860).
 * $requireMask is true for a server (client frames must be masked); false
 * accepts masked and unmasked frames alike.
 *
 * Thread-safety: stateless; safe to share between connections.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $result = (new FrameParser(maxPayloadSize: 65536))->parse($buffer);
 *     if ($result !== null) {
 *         [$frame, $used] = $result;
 *         $buffer = substr($buffer, $used);
 *     }
 *
 * References: RFC 6455 §5.1 (masking), §5.2 (base framing, minimal
 * length), §5.3 (masking key), §5.5 (control frames), §10.4 (limits);
 * ADR-001.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-5.2
 */
final readonly class FrameParser
{
    public function __construct(
        public int $maxPayloadSize = 1_048_576,
        public bool $requireMask = true,
    ) {
        if ($maxPayloadSize < 1) {
            throw InvalidConfigurationException::nonPositiveLimit('maxPayloadSize');
        }
    }

    /**
     * @return array{Frame, int}|null the frame and the bytes it used, or null for more data
     */
    public function parse(string $buffer): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $opcode = $this->opcode($first, $second);
        $header = $this->length($buffer, $second & 0x7F);

        if ($header === null) {
            return null;
        }

        [$length, $offset] = $header;
        $masked = ($second & 0x80) !== 0;
        $end = $offset + ($masked ? 4 : 0) + $length;

        if (strlen($buffer) < $end) {
            return null;
        }

        $payload = substr($buffer, $end - $length, $length);
        $payload = $masked ? self::applyMask($payload, substr($buffer, $offset, 4)) : $payload;

        return [new Frame(($first & 0x80) !== 0, $opcode, $payload, $masked), $end];
    }

    /**
     * XORs $data with the 4-byte key repeated to its length (RFC 6455
     * §5.3); the same call masks and unmasks.
     */
    public static function applyMask(string $data, string $maskingKey): string
    {
        return $data ^ str_pad('', strlen($data), $maskingKey);
    }

    /** The opcode, after the rules that need only the first two bytes. */
    private function opcode(int $first, int $second): Opcode
    {
        if (($first & 0x70) !== 0) {
            throw FrameException::reservedBits();
        }

        $opcode = Opcode::tryFrom($first & 0x0F) ?? throw FrameException::reservedOpcode();

        if ($this->requireMask && ($second & 0x80) === 0) {
            throw FrameException::unmaskedClientFrame();
        }

        if ($opcode->isControl() && ($first & 0x80) === 0) {
            throw FrameException::fragmentedControlFrame();
        }

        return $opcode->isControl() && ($second & 0x7F) > 125 ? throw FrameException::controlFrameTooLarge() : $opcode;
    }

    /**
     * The payload length and the offset after it, or null while the
     * extended length is still missing.
     *
     * @return array{int, int}|null
     */
    private function length(string $buffer, int $short): ?array
    {
        $header = match ($short) {
            126 => self::extended($buffer, 2, 126),
            127 => self::extended($buffer, 8, 65536),
            default => [$short, 2],
        };

        if ($header !== null && $header[0] > $this->maxPayloadSize) {
            throw FrameException::frameTooLarge();
        }

        return $header;
    }

    /**
     * A 16- or 64-bit length in network order, which must need its size.
     *
     * @return array{int, int}|null
     */
    private static function extended(string $buffer, int $bytes, int $minimum): ?array
    {
        if (strlen($buffer) < 2 + $bytes) {
            return null;
        }

        if ((ord($buffer[2]) & 0x80) !== 0 && $bytes === 8) {
            throw FrameException::lengthOutOfRange();
        }

        $length = 0;

        foreach (str_split(substr($buffer, 2, $bytes)) as $byte) {
            $length = ($length << 8) | ord($byte);
        }

        return $length < $minimum ? throw FrameException::nonMinimalLength() : [$length, 2 + $bytes];
    }
}
