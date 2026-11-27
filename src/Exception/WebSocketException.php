<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Exception;

use KaririCode\WebSocket\Enum\CloseCode;

/**
 * Base exception for WebSocket protocol errors (RFC 6455 §7.4).
 *
 * ARFA 1.3 P1: carries an immutable {@see CloseCode} enum value so
 * the server can send the appropriate close frame before dropping
 * the connection.
 *
 * Spec V4.0: non-final by design — extended by {@see FrameException}
 * for wire-level violations and {@see HandshakeException} for opening
 * handshake failures. Each subclass adds domain-specific context
 * (e.g. HTTP status code for handshake errors).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455 WebSocket Protocol
 */
class WebSocketException extends \RuntimeException
{
    public function __construct(
        public readonly CloseCode $closeCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?: $closeCode->reason(),
            $closeCode->numericCode(),
            $previous,
        );
    }
}

