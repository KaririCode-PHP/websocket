<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Handshake;

use KaririCode\WebSocket\Exception\HandshakeException;
use KaririCode\WebSocket\Exception\InvalidConfigurationException;

/**
 * The server side of the WebSocket opening handshake (RFC 6455 §4.2):
 * finds the request head in the bytes a client sent (refusing heads above
 * the limit), parses it strictly (GET, an origin-form target, HTTP/1.1,
 * header fields that follow the HTTP grammar, no repeated single-valued
 * fields), checks Host, Upgrade, Connection, the version and the key,
 * applies the Origin allow-list, picks the first subprotocol the client
 * offers that the server speaks, and writes the 101 response. A refused
 * handshake gets a fixed response from rejection(), which never repeats
 * what the client sent.
 *
 * ARFA 1.3: P4 (HTTP upgrade in, WebSocket out). Spec V4.3.3: final
 * readonly, configured once, no state between handshakes.
 *
 * Preconditions: each allowed origin is a serialised origin,
 * scheme://host[:port] (InvalidConfigurationException 1862); each
 * subprotocol is an HTTP token (1863); $maxRequestSize ≥ 1 (1860). An empty
 * origin list accepts any Origin and requests without one (non-browser
 * clients send none); browsers always send it, so a browser-facing server
 * should list its origins (cross-site WebSocket hijacking).
 *
 * Thread-safety: stateless; safe to share between connections.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $negotiator = new HandshakeNegotiator(allowedOrigins: ['https://app.example.com'], subprotocols: ['chat.v2']);
 *     $head = $negotiator->head($buffer);              // null until "\r\n\r\n" arrives
 *     $response = $negotiator->negotiate($negotiator->parseRequest($head));
 *
 * References: RFC 6455 §4.1, §4.2.1, §4.2.2, §4.4, §10.2 (origin), §11.3
 * (header fields); RFC 9112 §2.2, §3, §5; RFC 6454; OWASP WebSocket
 * Security Cheat Sheet; ADR-005.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-4.2
 */
final readonly class HandshakeNegotiator
{
    /** RFC 6455 §1.3: appended to the key before hashing. */
    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private const string TOKEN = '/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/';

    private const string FIELD_VALUE = '/\A[\x09\x20-\x7E\x80-\xFF]*\z/';

    /** scheme "://" host [":" port], as a browser serialises an origin. */
    private const string ORIGIN = '/\A[a-z][a-z0-9+\-.]*:\/\/(?:\[[0-9a-f:.]+\]|[a-z0-9\-._~%]+)(?::\d{1,5})?\z/i';

    /** 16 bytes in base64: 22 characters and two "=". */
    private const string KEY = '/\A[A-Za-z0-9+\/]{22}==\z/';

    private const array SINGLE_VALUED = ['host', 'origin', 'sec-websocket-key', 'sec-websocket-version'];

    private const array PHRASES = [400 => 'Bad Request', 403 => 'Forbidden', 426 => 'Upgrade Required', 431 => 'Request Header Fields Too Large'];

    /**
     * @param list<string> $allowedOrigins serialised origins; empty accepts any
     * @param list<string> $subprotocols   subprotocols the server speaks
     */
    public function __construct(
        public array $allowedOrigins = [],
        public array $subprotocols = [],
        public int $maxRequestSize = 8192,
    ) {
        foreach ($allowedOrigins as $origin) {
            if (preg_match(self::ORIGIN, $origin) !== 1) {
                throw InvalidConfigurationException::invalidOrigin($origin);
            }
        }

        foreach ($subprotocols as $subprotocol) {
            if (preg_match(self::TOKEN, $subprotocol) !== 1) {
                throw InvalidConfigurationException::invalidSubprotocol($subprotocol);
            }
        }

        if ($maxRequestSize < 1) {
            throw InvalidConfigurationException::nonPositiveLimit('maxRequestSize');
        }
    }

    /**
     * The request head at the start of $buffer, up to and including the
     * blank line; null while it is incomplete.
     */
    public function head(string $buffer): ?string
    {
        $end = strpos($buffer, "\r\n\r\n");
        $size = $end === false ? strlen($buffer) : $end + 4;

        if ($size > $this->maxRequestSize) {
            throw HandshakeException::tooLarge();
        }

        return $end === false ? null : substr($buffer, 0, $size);
    }

    public function parseRequest(string $head): HandshakeRequest
    {
        if (strlen($head) > $this->maxRequestSize) {
            throw HandshakeException::tooLarge();
        }

        $lines = explode("\r\n", str_ends_with($head, "\r\n\r\n") ? substr($head, 0, -4) : throw HandshakeException::malformedHeader());
        $target = self::target(array_shift($lines));
        $headers = [];

        foreach ($lines as $line) {
            [$name, $value] = self::field($line);
            $headers[$name][] = $value;
        }

        foreach (self::SINGLE_VALUED as $name) {
            if (count($headers[$name] ?? []) > 1) {
                throw HandshakeException::duplicateHeader($name);
            }
        }

        return new HandshakeRequest($target, $headers);
    }

    public function negotiate(HandshakeRequest $request): HandshakeResponse
    {
        if (($request->header('host') ?? '') === '') {
            throw HandshakeException::missingHost();
        }

        if (!self::lists($request->header('upgrade'), 'websocket')) {
            throw HandshakeException::missingUpgrade();
        }

        if (!self::lists($request->header('connection'), 'upgrade')) {
            throw HandshakeException::missingConnectionUpgrade();
        }

        if ($request->header('sec-websocket-version') !== '13') {
            throw HandshakeException::unsupportedVersion();
        }

        $key = $request->header('sec-websocket-key') ?? '';

        if (preg_match(self::KEY, $key) !== 1) {
            throw HandshakeException::invalidKey();
        }

        $this->checkOrigin($request->header('origin'));
        $subprotocol = $this->subprotocol($request->header('sec-websocket-protocol'));

        return new HandshakeResponse(
            "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . self::acceptKey($key) . "\r\n"
            . ($subprotocol === null ? '' : 'Sec-WebSocket-Protocol: ' . $subprotocol . "\r\n") . "\r\n",
            $subprotocol,
        );
    }

    /** RFC 6455 §4.2.2: base64(SHA-1(key . GUID)). */
    public static function acceptKey(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }

    /**
     * A fixed response for a refused handshake: the status, "Connection:
     * close", and Sec-WebSocket-Version: 13 with 426 (RFC 6455 §4.4).
     */
    public static function rejection(int $status): string
    {
        $status = isset(self::PHRASES[$status]) ? $status : 400;

        return 'HTTP/1.1 ' . $status . ' ' . self::PHRASES[$status] . "\r\n"
            . ($status === 426 ? "Sec-WebSocket-Version: 13\r\n" : '')
            . "Connection: close\r\nContent-Length: 0\r\n\r\n";
    }

    private static function target(?string $requestLine): string
    {
        if (preg_match('/\A(\S+) (\S+) (\S+)\z/', $requestLine ?? '', $match) !== 1 || preg_match(self::TOKEN, $match[1]) !== 1) {
            throw HandshakeException::malformedRequestLine();
        }

        if ($match[1] !== 'GET') {
            throw HandshakeException::methodNotGet();
        }

        if ($match[3] !== 'HTTP/1.1') {
            throw HandshakeException::unsupportedHttpVersion();
        }

        return str_starts_with($match[2], '/') && preg_match('/[\x00-\x1F\x7F]/', $match[2]) !== 1 ? $match[2] : throw HandshakeException::malformedRequestLine();
    }

    /**
     * @return array{string, string} lower-case name, value without surrounding spaces and tabs
     */
    private static function field(string $line): array
    {
        $colon = strpos($line, ':');
        $name = $colon === false ? '' : substr($line, 0, $colon);
        $value = $colon === false ? '' : trim(substr($line, $colon + 1), " \t");

        if (preg_match(self::TOKEN, $name) !== 1 || preg_match(self::FIELD_VALUE, $value) !== 1) {
            throw HandshakeException::malformedHeader();
        }

        return [strtolower($name), $value];
    }

    /** True when the comma-separated list holds $token, ignoring case. */
    private static function lists(?string $value, string $token): bool
    {
        foreach (explode(',', $value ?? '') as $item) {
            if (strcasecmp(trim($item, " \t"), $token) === 0) {
                return true;
            }
        }

        return false;
    }

    private function checkOrigin(?string $origin): void
    {
        if ($this->allowedOrigins === []) {
            return;
        }

        foreach ($this->allowedOrigins as $allowed) {
            if ($origin !== null && strcasecmp($allowed, $origin) === 0) {
                return;
            }
        }

        throw HandshakeException::originRejected();
    }

    /** The first subprotocol the client offers that the server speaks, or null. */
    private function subprotocol(?string $offered): ?string
    {
        foreach (explode(',', $offered ?? '') as $candidate) {
            $candidate = trim($candidate, " \t");

            if (in_array($candidate, $this->subprotocols, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
