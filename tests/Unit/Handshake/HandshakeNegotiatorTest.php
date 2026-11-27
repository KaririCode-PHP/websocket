<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Handshake;

use KaririCode\WebSocket\Exception\HandshakeException;
use KaririCode\WebSocket\Handshake\HandshakeNegotiator;
use PHPUnit\Framework\TestCase;

final class HandshakeNegotiatorTest extends TestCase
{
    private HandshakeNegotiator $negotiator;

    protected function setUp(): void
    {
        $this->negotiator = new HandshakeNegotiator();
    }

    // ── Request Parsing ──────────────────────────────────────────────────

    public function testParseValidRequest(): void
    {
        $raw = "GET /ws/chat HTTP/1.1\r\n"
            . "Host: example.com\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";

        $parsed = $this->negotiator->parseRequest($raw);

        self::assertSame('GET', $parsed['method']);
        self::assertSame('/ws/chat', $parsed['path']);
        self::assertSame('websocket', $parsed['headers']['upgrade']);
        self::assertSame('dGhlIHNhbXBsZSBub25jZQ==', $parsed['headers']['sec-websocket-key']);
    }

    public function testParseInvalidRequestLineThrows(): void
    {
        $raw = "POST /invalid HTTP/1.1\r\n\r\n";

        $this->expectException(HandshakeException::class);
        $this->negotiator->parseRequest($raw);
    }

    // ── Handshake Negotiation ────────────────────────────────────────────

    public function testSuccessfulNegotiation(): void
    {
        $headers = [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'sec-websocket-version' => '13',
        ];

        $response = $this->negotiator->negotiate($headers);

        self::assertStringContainsString('HTTP/1.1 101 Switching Protocols', $response);
        self::assertStringContainsString('Upgrade: websocket', $response);
        self::assertStringContainsString('Connection: Upgrade', $response);
        self::assertStringContainsString('Sec-WebSocket-Accept:', $response);
    }

    public function testAcceptKeyComputation(): void
    {
        // RFC 6455 §4.2.2 reference test vector
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';
        $expected = 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=';

        $accept = HandshakeNegotiator::computeAcceptKey($key);
        self::assertSame($expected, $accept);
    }

    public function testMissingUpgradeHeaderThrows(): void
    {
        $headers = [
            'connection' => 'Upgrade',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'sec-websocket-version' => '13',
        ];

        $this->expectException(HandshakeException::class);
        $this->negotiator->negotiate($headers);
    }

    public function testWrongUpgradeValueThrows(): void
    {
        $headers = [
            'upgrade' => 'h2c',
            'connection' => 'Upgrade',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'sec-websocket-version' => '13',
        ];

        $this->expectException(HandshakeException::class);
        $this->negotiator->negotiate($headers);
    }

    public function testMissingConnectionHeaderThrows(): void
    {
        $headers = [
            'upgrade' => 'websocket',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'sec-websocket-version' => '13',
        ];

        $this->expectException(HandshakeException::class);
        $this->negotiator->negotiate($headers);
    }

    public function testUnsupportedVersionThrows(): void
    {
        $headers = [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'sec-websocket-version' => '8',
        ];

        $this->expectException(HandshakeException::class);
        $this->negotiator->negotiate($headers);
    }

    public function testInvalidKeyThrows(): void
    {
        $headers = [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-key' => 'not-valid-base64',
            'sec-websocket-version' => '13',
        ];

        $this->expectException(HandshakeException::class);
        $this->negotiator->negotiate($headers);
    }

    public function testEmptyKeyThrows(): void
    {
        $headers = [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-key' => '',
            'sec-websocket-version' => '13',
        ];

        $this->expectException(HandshakeException::class);
        $this->negotiator->negotiate($headers);
    }

    // ── Origin Validation ────────────────────────────────────────────────

    public function testOriginAllowlistAccepts(): void
    {
        $negotiator = new HandshakeNegotiator(allowedOrigins: ['https://example.com']);

        $headers = [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'sec-websocket-version' => '13',
            'origin' => 'https://example.com',
        ];

        $response = $negotiator->negotiate($headers);
        self::assertStringContainsString('101', $response);
    }

    public function testOriginAllowlistRejects(): void
    {
        $negotiator = new HandshakeNegotiator(allowedOrigins: ['https://example.com']);

        $headers = [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'sec-websocket-version' => '13',
            'origin' => 'https://evil.com',
        ];

        $this->expectException(HandshakeException::class);
        $negotiator->negotiate($headers);
    }

    public function testEmptyAllowlistAcceptsAll(): void
    {
        $headers = [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'sec-websocket-version' => '13',
            'origin' => 'https://anything.com',
        ];

        $response = $this->negotiator->negotiate($headers);
        self::assertStringContainsString('101', $response);
    }

    // ── Subprotocol Negotiation ──────────────────────────────────────────

    public function testSubprotocolNegotiation(): void
    {
        $negotiator = new HandshakeNegotiator(
            supportedProtocols: ['graphql-ws', 'chat'],
        );

        $headers = [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'sec-websocket-version' => '13',
            'sec-websocket-protocol' => 'chat, superchat',
        ];

        $response = $negotiator->negotiate($headers);
        self::assertStringContainsString('Sec-WebSocket-Protocol: chat', $response);
    }

    public function testSubprotocolNoMatch(): void
    {
        $negotiator = new HandshakeNegotiator(
            supportedProtocols: ['graphql-ws'],
        );

        $headers = [
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'sec-websocket-version' => '13',
            'sec-websocket-protocol' => 'mqtt',
        ];

        $response = $negotiator->negotiate($headers);
        self::assertStringNotContainsString('Sec-WebSocket-Protocol', $response);
    }

    // ── Exception Factories ──────────────────────────────────────────────

    public function testHandshakeExceptionFactories(): void
    {
        $missing = HandshakeException::missingHeaders();
        self::assertSame(400, $missing->httpStatusCode);

        $invalidKey = HandshakeException::invalidKey();
        self::assertSame(400, $invalidKey->httpStatusCode);

        $version = HandshakeException::unsupportedVersion('8');
        self::assertSame(426, $version->httpStatusCode);
        self::assertStringContainsString('8', $version->getMessage());

        $origin = HandshakeException::originRejected('https://evil.com');
        self::assertSame(403, $origin->httpStatusCode);
    }
}
