<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Exception;

use KaririCode\Exception\ProtocolException;

/**
 * The opening handshake of a client cannot be accepted (RFC 6455 §4.2.1):
 * the request head is too large, its request line or a header field is
 * malformed, the method is not GET or the version not HTTP/1.1, Host,
 * Upgrade or Connection is missing, the WebSocket version is not 13, the
 * key is not 16 bytes in base64, a single-valued field repeats, or the
 * Origin is not allowed. Each carries the HTTP status the server answers
 * with: 400, 403, 426 (with Sec-WebSocket-Version: 13) or 431.
 *
 * Preconditions: messages name the rule, never a header value the client
 * sent (errata E-047); the server answers with a fixed status line, never
 * with the message.
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $accepted = $negotiator->negotiate($negotiator->parseRequest($head));
 *     } catch (HandshakeException $e) {
 *         fwrite($socket, HandshakeNegotiator::rejection($e->status));
 *     }
 *
 * References: RFC 6455 §4.1, §4.2.1, §4.2.2, §4.4; RFC 9110 §15.5.1 (400),
 * §15.5.4 (403), §15.5.22 (426); RFC 6585 §5 (431); ADR-003, ADR-005.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-4.2.1
 */
final class HandshakeException extends ProtocolException
{
    private function __construct(WsCode $code, public readonly int $status, string $message)
    {
        parent::__construct($code, $message);
    }

    public static function tooLarge(): self
    {
        return new self(WsCode::HandshakeTooLarge, 431, 'The handshake request head exceeds the configured limit.');
    }

    public static function malformedRequestLine(): self
    {
        return new self(WsCode::MalformedRequestLine, 400, 'The request line must be "GET", an origin-form target and "HTTP/1.1".');
    }

    public static function methodNotGet(): self
    {
        return new self(WsCode::MethodNotGet, 400, 'The handshake method must be GET.');
    }

    public static function unsupportedHttpVersion(): self
    {
        return new self(WsCode::UnsupportedHttpVersion, 400, 'The handshake must use HTTP/1.1.');
    }

    public static function malformedHeader(): self
    {
        return new self(WsCode::MalformedHeader, 400, 'A header field of the handshake breaks the HTTP grammar.');
    }

    public static function missingHost(): self
    {
        return new self(WsCode::MissingHost, 400, 'The handshake needs a Host header.');
    }

    public static function missingUpgrade(): self
    {
        return new self(WsCode::MissingUpgrade, 400, 'The Upgrade header must list "websocket".');
    }

    public static function missingConnectionUpgrade(): self
    {
        return new self(WsCode::MissingConnectionUpgrade, 400, 'The Connection header must list "Upgrade".');
    }

    public static function unsupportedVersion(): self
    {
        return new self(WsCode::UnsupportedVersion, 426, 'Sec-WebSocket-Version must be 13.');
    }

    public static function invalidKey(): self
    {
        return new self(WsCode::InvalidKey, 400, 'Sec-WebSocket-Key must be 16 bytes in base64.');
    }

    public static function originRejected(): self
    {
        return new self(WsCode::OriginRejected, 403, 'The Origin of the handshake is not allowed.');
    }

    public static function duplicateHeader(string $name): self
    {
        return new self(WsCode::DuplicateHeader, 400, sprintf('The header %s may appear only once.', self::quote($name)));
    }
}
