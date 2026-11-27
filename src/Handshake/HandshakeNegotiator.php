<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Handshake;

use KaririCode\WebSocket\Exception\HandshakeException;

/**
 * Validates and completes the WebSocket opening handshake (RFC 6455 §4.2).
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): the handshake bridges HTTP/1.1
 * upgrade semantics into the WebSocket protocol. This class handles
 * the HTTP layer; post-handshake framing is delegated to
 * {@see FrameParser} and {@see FrameWriter}.
 *
 * Spec V4.0: final class, zero mutable state. Configuration
 * (allowed origins, supported subprotocols) is injected via constructor.
 *
 * Server-side handshake flow:
 *   1. Parse incoming HTTP/1.1 Upgrade request
 *   2. Validate required headers (Upgrade, Connection, Sec-WebSocket-Key, Version)
 *   3. Optionally validate Origin against allowlist
 *   4. Compute Sec-WebSocket-Accept from the client key (RFC 6455 §4.2.2)
 *   5. Negotiate subprotocol if requested
 *   6. Generate the 101 Switching Protocols response
 *
 * The GUID "258EAFA5-E914-47DA-95CA-C5AB0DC85B11" is mandated by
 * RFC 6455 §4.2.2 for the key→accept derivation.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-4.2 Server-Side Requirements
 */
final class HandshakeNegotiator
{
    // RFC 6455 §4.2.2: globally unique identifier
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const SUPPORTED_VERSION = '13';

    /**
     * @param list<string> $allowedOrigins Empty = accept all origins
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455#section-4.2 Server-Side Requirements
     */
    public function __construct(
        private readonly array $allowedOrigins = [],
        private readonly array $supportedProtocols = [],
    ) {
    }

    /**
     * Parses the raw HTTP request into structured headers.
     *
     * @return array{method: string, path: string, headers: array<string, string>}
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455#section-4.2 Server-Side Requirements
     */
    public function parseRequest(string $rawRequest): array
    {
        $lines = explode("\r\n", $rawRequest);
        $requestLine = array_shift($lines);

        if ($requestLine === null || !preg_match('#^(GET)\s+(.+)\s+HTTP/1\.1$#i', $requestLine, $matches)) {
            throw new HandshakeException(400, 'Invalid HTTP request line');
        }

        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                break;
            }
            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colonPos)));
            $value = trim(substr($line, $colonPos + 1));
            $headers[$name] = $value;
        }

        return [
            'method' => strtoupper($matches[1]),
            'path' => $matches[2],
            'headers' => $headers,
        ];
    }

    /**
     * Validates the handshake headers and returns the 101 response.
     *
     * @param array<string, string> $headers Lowercase header names
     * @return string Complete HTTP 101 response to write to the socket
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455#section-4.2 Server-Side Requirements
     */
    public function negotiate(array $headers): string
    {
        if (strtolower($headers['upgrade'] ?? '') !== 'websocket') {
            throw HandshakeException::missingHeaders();
        }

        if (stripos($headers['connection'] ?? '', 'Upgrade') === false) {
            throw HandshakeException::missingHeaders();
        }

        $version = $headers['sec-websocket-version'] ?? '';
        if ($version !== self::SUPPORTED_VERSION) {
            throw HandshakeException::unsupportedVersion($version);
        }

        // RFC 6455 §4.2.1: key must be 16 bytes base64-encoded (= 24 chars)
        $key = $headers['sec-websocket-key'] ?? '';
        if ($key === '' || strlen(base64_decode($key, true) ?: '') !== 16) {
            throw HandshakeException::invalidKey();
        }

        if ($this->allowedOrigins !== []) {
            $origin = $headers['origin'] ?? '';
            if (!in_array($origin, $this->allowedOrigins, true)) {
                throw HandshakeException::originRejected($origin);
            }
        }

        // RFC 6455 §4.2.2: SHA-1(key + GUID) → base64
        $acceptKey = self::computeAcceptKey($key);

        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$acceptKey}\r\n";

        // Negotiate subprotocol if requested
        $requestedProtocol = $headers['sec-websocket-protocol'] ?? '';
        if ($requestedProtocol !== '' && $this->supportedProtocols !== []) {
            $candidates = array_map('trim', explode(',', $requestedProtocol));
            $selected = array_values(array_intersect($candidates, $this->supportedProtocols));
            if ($selected !== []) {
                $response .= "Sec-WebSocket-Protocol: {$selected[0]}\r\n";
            }
        }

        $response .= "\r\n";

        return $response;
    }

    /**
     * RFC 6455 §4.2.2: SHA-1(key + GUID) → base64.
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455#section-4.2 Server-Side Requirements
     */
    public static function computeAcceptKey(string $clientKey): string
    {
        return base64_encode(sha1($clientKey . self::GUID, true));
    }
}

