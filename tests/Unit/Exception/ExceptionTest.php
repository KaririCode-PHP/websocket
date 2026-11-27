<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Exception;

use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Exception\FrameException;
use KaririCode\WebSocket\Exception\HandshakeException;
use KaririCode\WebSocket\Exception\WebSocketException;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    // ── WebSocketException ───────────────────────────────────────────────

    public function testWebSocketExceptionCarriesCloseCode(): void
    {
        $ex = new WebSocketException(CloseCode::ProtocolError, 'test error');

        self::assertSame(CloseCode::ProtocolError, $ex->closeCode);
        self::assertSame('test error', $ex->getMessage());
        self::assertSame(1002, $ex->getCode());
    }

    public function testWebSocketExceptionDefaultMessage(): void
    {
        $ex = new WebSocketException(CloseCode::NormalClosure);

        self::assertSame('Normal Closure', $ex->getMessage());
    }

    public function testWebSocketExceptionChaining(): void
    {
        $previous = new \RuntimeException('root cause');
        $ex = new WebSocketException(CloseCode::InternalError, 'wrapped', $previous);

        self::assertSame($previous, $ex->getPrevious());
    }

    // ── FrameException ───────────────────────────────────────────────────

    public function testReservedOpcode(): void
    {
        $ex = FrameException::reservedOpcode(0x3);

        self::assertSame(CloseCode::ProtocolError, $ex->closeCode);
        self::assertStringContainsString('0x3', $ex->getMessage());
    }

    public function testControlFrameTooLarge(): void
    {
        $ex = FrameException::controlFrameTooLarge(200);

        self::assertSame(CloseCode::ProtocolError, $ex->closeCode);
        self::assertStringContainsString('200', $ex->getMessage());
    }

    public function testFragmentedControlFrame(): void
    {
        $ex = FrameException::fragmentedControlFrame();

        self::assertSame(CloseCode::ProtocolError, $ex->closeCode);
    }

    public function testPayloadTooLarge(): void
    {
        $ex = FrameException::payloadTooLarge(2000, 1000);

        self::assertSame(CloseCode::MessageTooBig, $ex->closeCode);
        self::assertStringContainsString('2000', $ex->getMessage());
        self::assertStringContainsString('1000', $ex->getMessage());
    }

    public function testInvalidUtf8(): void
    {
        $ex = FrameException::invalidUtf8();

        self::assertSame(CloseCode::InvalidPayload, $ex->closeCode);
    }

    public function testMaskRequired(): void
    {
        $ex = FrameException::maskRequired();

        self::assertSame(CloseCode::ProtocolError, $ex->closeCode);
        self::assertStringContainsString('masked', $ex->getMessage());
    }

    // ── HandshakeException ───────────────────────────────────────────────

    public function testHandshakeExceptionCarriesHttpStatus(): void
    {
        $ex = new HandshakeException(400, 'bad request');

        self::assertSame(400, $ex->httpStatusCode);
        self::assertSame(CloseCode::ProtocolError, $ex->closeCode);
    }

    public function testMissingHeaders(): void
    {
        $ex = HandshakeException::missingHeaders();
        self::assertSame(400, $ex->httpStatusCode);
    }

    public function testInvalidKey(): void
    {
        $ex = HandshakeException::invalidKey();
        self::assertSame(400, $ex->httpStatusCode);
    }

    public function testUnsupportedVersion(): void
    {
        $ex = HandshakeException::unsupportedVersion('8');
        self::assertSame(426, $ex->httpStatusCode);
    }

    public function testOriginRejected(): void
    {
        $ex = HandshakeException::originRejected('https://evil.com');
        self::assertSame(403, $ex->httpStatusCode);
        self::assertStringContainsString('evil.com', $ex->getMessage());
    }

    // ── Inheritance ──────────────────────────────────────────────────────

    public function testFrameExceptionExtendsWebSocketException(): void
    {
        $ex = FrameException::maskRequired();

        self::assertInstanceOf(WebSocketException::class, $ex);
        self::assertInstanceOf(\RuntimeException::class, $ex);
    }

    public function testHandshakeExceptionExtendsWebSocketException(): void
    {
        $ex = HandshakeException::missingHeaders();

        self::assertInstanceOf(WebSocketException::class, $ex);
        self::assertInstanceOf(\RuntimeException::class, $ex);
    }
}
