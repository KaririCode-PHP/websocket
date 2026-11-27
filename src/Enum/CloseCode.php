<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Enum;

/**
 * The status codes of a WebSocket close (RFC 6455 §7.4.1 and the IANA
 * registry), backed by the number sent on the wire (errata E-003).
 * 1005, 1006 and 1015 describe what happened locally and are never sent;
 * 3000–4999 belong to libraries and applications and have no case, so
 * isValid() takes a plain number.
 *
 * ARFA 1.3: P1 (immutable cases). Spec V4.3.3: backed enum with a
 * documented external contract (E-003).
 *
 * Preconditions: none.
 *
 * Thread-safety: enum cases are immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     CloseCode::isValid(4001);                    // true: application range
 *     CloseCode::isValid(1005);                    // false: never on the wire
 *     CloseCode::MessageTooBig->reason();          // "Message Too Big"
 *
 * References: RFC 6455 §7.4.1, §7.4.2, §11.7; IANA WebSocket Close Code
 * Number Registry.
 *
 * @see https://www.iana.org/assignments/websocket/websocket.xhtml#close-code-number
 */
enum CloseCode: int
{
    case NormalClosure = 1000;
    case GoingAway = 1001;
    case ProtocolError = 1002;
    case UnsupportedData = 1003;
    case NoStatusReceived = 1005;
    case AbnormalClosure = 1006;
    case InvalidPayload = 1007;
    case PolicyViolation = 1008;
    case MessageTooBig = 1009;
    case MandatoryExtension = 1010;
    case InternalError = 1011;
    case ServiceRestart = 1012;
    case TryAgainLater = 1013;
    case BadGateway = 1014;
    case TlsHandshake = 1015;

    /** A short phrase for the code, short enough to be a close reason (≤ 123 bytes). */
    public function reason(): string
    {
        return match ($this) {
            self::NormalClosure => 'Normal Closure',
            self::GoingAway => 'Going Away',
            self::ProtocolError => 'Protocol Error',
            self::UnsupportedData => 'Unsupported Data',
            self::NoStatusReceived => 'No Status Received',
            self::AbnormalClosure => 'Abnormal Closure',
            self::InvalidPayload => 'Invalid Payload Data',
            self::PolicyViolation => 'Policy Violation',
            self::MessageTooBig => 'Message Too Big',
            self::MandatoryExtension => 'Mandatory Extension',
            self::InternalError => 'Internal Error',
            self::ServiceRestart => 'Service Restart',
            self::TryAgainLater => 'Try Again Later',
            self::BadGateway => 'Bad Gateway',
            self::TlsHandshake => 'TLS Handshake',
        };
    }

    /**
     * True for a code a peer may put in a Close frame: 1000–1003,
     * 1007–1014 and the application range 3000–4999 (RFC 6455 §7.4).
     */
    public static function isValid(int $code): bool
    {
        return ($code >= 1000 && $code <= 1003) || ($code >= 1007 && $code <= 1014) || ($code >= 3000 && $code <= 4999);
    }
}
