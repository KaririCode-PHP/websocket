<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Frame;

use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Exception\FrameException;

/**
 * The status code and reason a peer put in a Close frame (RFC 6455
 * §5.5.1). An empty payload means no status (code null, reported as 1005
 * NoStatusReceived); otherwise the first two bytes are a code a peer may
 * send and the rest is a UTF-8 reason. A one-byte payload, a code such as
 * 1005 or 2000, or a reason that is not UTF-8 is a protocol failure.
 *
 * ARFA 1.3: P1 (immutable). Spec V4.3.3: final readonly Value Object
 * built by a named parser.
 *
 * Preconditions: $payload is the unmasked payload of a Close frame, at
 * most 125 bytes (FrameParser enforces it).
 *
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $close = ClosePayload::parse($frame->payload);
 *     $close->status();  // 1000, or 1005 when the client sent no code
 *
 * References: RFC 6455 §5.5.1, §7.1.5, §7.1.6, §7.4.1, §7.4.2; ADR-001.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-5.5.1
 */
final readonly class ClosePayload
{
    private function __construct(
        public ?int $code,
        public string $reason,
    ) {}

    public static function parse(string $payload): self
    {
        if ($payload === '') {
            return new self(null, '');
        }

        if (strlen($payload) < 2) {
            throw FrameException::invalidClosePayload();
        }

        $code = (ord($payload[0]) << 8) | ord($payload[1]);

        if (!CloseCode::isValid($code)) {
            throw FrameException::invalidCloseCode();
        }

        $reason = substr($payload, 2);

        return preg_match('//u', $reason) === 1 ? new self($code, $reason) : throw FrameException::invalidCloseReason();
    }

    /** The code to report: the one received, or 1005 when the frame had none. */
    public function status(): int
    {
        return $this->code ?? CloseCode::NoStatusReceived->value;
    }
}
