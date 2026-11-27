<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Enum;

/**
 * WebSocket frame opcodes per RFC 6455 §5.2.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): enum cases are
 * inherently immutable value types.
 *
 * Spec V4.0 §2.1: pure enum — no backed values. Numeric codes
 * derived via match() so the enum remains closed for modification.
 *
 * Data frames (0x0–0x2) carry application payload; control frames
 * (0x8–0xA) manage connection lifecycle and MUST NOT exceed 125 bytes
 * of payload (RFC 6455 §5.5). Static memoization in tryFromByteValue()
 * avoids repeated array construction on the hot path.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5.2
 */
enum Opcode
{
    case Continuation;
    case Text;
    case Binary;
    case Close;
    case Ping;
    case Pong;

    public function byteValue(): int
    {
        return match ($this) {
            self::Continuation => 0x0,
            self::Text         => 0x1,
            self::Binary       => 0x2,
            self::Close        => 0x8,
            self::Ping         => 0x9,
            self::Pong         => 0xA,
        };
    }

    public function isControl(): bool
    {
        return match ($this) {
            self::Close, self::Ping, self::Pong => true,
            default => false,
        };
    }

    public function isData(): bool
    {
        return !$this->isControl();
    }

    public static function tryFromByteValue(int $value): ?self
    {
        static $map = null;

        $map ??= array_reduce(
            self::cases(),
            static function (array $carry, self $case): array {
                $carry[$case->byteValue()] = $case;
                return $carry;
            },
            [],
        );

        return $map[$value] ?? null;
    }
}
