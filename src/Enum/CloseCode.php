<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Enum;

/**
 * WebSocket close status codes per RFC 6455 §7.4.1.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): :.
 *
 * Spec V4.0 §2.1: pure enum — numeric values via match().
 * enum cases are inherently immutable value types.
 *
 * Code ranges defined by IANA WebSocket Close Code Number Registry:
 *   1000–1015 — protocol-defined (this enum)
 *   3000–3999 — reserved for libraries/frameworks
 *   4000–4999 — reserved for applications (private use)
 *
 * Static memoization in tryFromCode() avoids repeated array
 * construction during close frame parsing.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-7.4.1
 */
enum CloseCode
{
    case NormalClosure;
    case GoingAway;
    case ProtocolError;
    case UnsupportedData;
    case NoStatusReceived;
    case AbnormalClosure;
    case InvalidPayload;
    case PolicyViolation;
    case MessageTooBig;
    case MandatoryExtension;
    case InternalError;
    case TlsHandshake;

    public function numericCode(): int
    {
        return match ($this) {
            self::NormalClosure      => 1000,
            self::GoingAway          => 1001,
            self::ProtocolError      => 1002,
            self::UnsupportedData    => 1003,
            self::NoStatusReceived   => 1005,
            self::AbnormalClosure    => 1006,
            self::InvalidPayload     => 1007,
            self::PolicyViolation    => 1008,
            self::MessageTooBig      => 1009,
            self::MandatoryExtension => 1010,
            self::InternalError      => 1011,
            self::TlsHandshake       => 1015,
        };
    }

    public function reason(): string
    {
        return match ($this) {
            self::NormalClosure      => 'Normal Closure',
            self::GoingAway          => 'Going Away',
            self::ProtocolError      => 'Protocol Error',
            self::UnsupportedData    => 'Unsupported Data',
            self::NoStatusReceived   => 'No Status Received',
            self::AbnormalClosure    => 'Abnormal Closure',
            self::InvalidPayload     => 'Invalid Payload Data',
            self::PolicyViolation    => 'Policy Violation',
            self::MessageTooBig      => 'Message Too Big',
            self::MandatoryExtension => 'Mandatory Extension',
            self::InternalError      => 'Internal Server Error',
            self::TlsHandshake       => 'TLS Handshake Failure',
        };
    }

    /**
     * Whether this code may appear in a close frame on the wire.
     * RFC 6455 §7.4.1: 1005, 1006, and 1015 MUST NOT be set.
     */
    public function isSendable(): bool
    {
        return match ($this) {
            self::NoStatusReceived, self::AbnormalClosure, self::TlsHandshake => false,
            default => true,
        };
    }

    public static function tryFromCode(int $code): ?self
    {
        static $map = null;

        $map ??= array_reduce(
            self::cases(),
            static function (array $carry, self $case): array {
                $carry[$case->numericCode()] = $case;
                return $carry;
            },
            [],
        );

        return $map[$code] ?? null;
    }
}
