<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Exception;

use KaririCode\Exception\ConfigurationException;

/**
 * The server, parser or handshake was configured with values that cannot
 * work: a size limit below 1 byte, a negative timeout, an allowed origin
 * that is not a serialised origin, or a subprotocol that is not an HTTP
 * token. Raised at bootstrap, before any client connects.
 *
 * Preconditions: none.
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     new ServerOptions(maxPayloadSize: 0);  // InvalidConfigurationException 1860
 *
 * References: RFC 6455 §4.1 (subprotocol tokens), §10.2 (origin);
 * RFC 6454 §6.2; ADR-003.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-10.2
 */
final class InvalidConfigurationException extends ConfigurationException
{
    public static function nonPositiveLimit(string $name): self
    {
        return new self(WsCode::NonPositiveLimit, sprintf('The limit %s must be at least 1.', self::quote($name)));
    }

    public static function negativeTimeout(string $name): self
    {
        return new self(WsCode::NegativeTimeout, sprintf('The timeout %s must be zero or more seconds.', self::quote($name)));
    }

    public static function invalidOrigin(string $origin): self
    {
        return new self(WsCode::InvalidOrigin, sprintf('The allowed origin %s is not scheme://host[:port].', self::quote($origin)));
    }

    public static function invalidSubprotocol(string $subprotocol): self
    {
        return new self(WsCode::InvalidSubprotocol, sprintf('The subprotocol %s is not an HTTP token.', self::quote($subprotocol)));
    }
}
