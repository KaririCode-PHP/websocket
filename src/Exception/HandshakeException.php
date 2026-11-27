<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Exception;

use KaririCode\WebSocket\Enum\CloseCode;

/**
 * Thrown when the opening handshake (RFC 6455 §4.2) fails.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): the handshake bridges HTTP
 * and WebSocket protocols. This exception carries both an HTTP status
 * code (for the error response) and a {@see CloseCode} (for logging).
 *
 * Spec V4.0: final class with static factories for each failure mode.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-4.2.2 Handshake Response
 */
final class HandshakeException extends WebSocketException
{
    public function __construct(
        public readonly int $httpStatusCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(CloseCode::ProtocolError, $message, $previous);
    }

    public static function missingHeaders(): self
    {
        return new self(400, 'Missing required WebSocket headers');
    }

    public static function invalidKey(): self
    {
        return new self(400, 'Invalid Sec-WebSocket-Key');
    }

    public static function unsupportedVersion(string $version): self
    {
        return new self(426, "Unsupported WebSocket version: {$version}");
    }

    public static function originRejected(string $origin): self
    {
        return new self(403, "Origin rejected: {$origin}");
    }
}

