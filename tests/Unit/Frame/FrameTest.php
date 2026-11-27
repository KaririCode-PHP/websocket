<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Frame;

use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Frame\Frame;
use PHPUnit\Framework\TestCase;

final class FrameTest extends TestCase
{
    public function testTextFactory(): void
    {
        $frame = Frame::text('Hello');

        self::assertTrue($frame->fin);
        self::assertSame(Opcode::Text, $frame->opcode);
        self::assertSame('Hello', $frame->payload);
        self::assertFalse($frame->masked);
        self::assertSame(5, $frame->payloadLength());
    }

    public function testTextFactoryNonFin(): void
    {
        $frame = Frame::text('chunk', fin: false);

        self::assertFalse($frame->fin);
        self::assertSame(Opcode::Text, $frame->opcode);
    }

    public function testBinaryFactory(): void
    {
        $data = "\x00\xFF\x80";
        $frame = Frame::binary($data);

        self::assertTrue($frame->fin);
        self::assertSame(Opcode::Binary, $frame->opcode);
        self::assertSame($data, $frame->payload);
        self::assertSame(3, $frame->payloadLength());
    }

    public function testCloseFactory(): void
    {
        $frame = Frame::close(1001, 'Going away');

        self::assertTrue($frame->fin);
        self::assertSame(Opcode::Close, $frame->opcode);

        // Payload: 2 bytes code + reason
        $code = unpack('n', substr($frame->payload, 0, 2))[1];
        $reason = substr($frame->payload, 2);
        self::assertSame(1001, $code);
        self::assertSame('Going away', $reason);
    }

    public function testCloseFactoryDefaults(): void
    {
        $frame = Frame::close();

        $code = unpack('n', substr($frame->payload, 0, 2))[1];
        self::assertSame(1000, $code);
        self::assertSame('', substr($frame->payload, 2));
    }

    public function testPingFactory(): void
    {
        $frame = Frame::ping('alive');

        self::assertTrue($frame->fin);
        self::assertSame(Opcode::Ping, $frame->opcode);
        self::assertSame('alive', $frame->payload);
    }

    public function testPingFactoryEmpty(): void
    {
        $frame = Frame::ping();
        self::assertSame('', $frame->payload);
    }

    public function testPongFactory(): void
    {
        $frame = Frame::pong('alive');

        self::assertTrue($frame->fin);
        self::assertSame(Opcode::Pong, $frame->opcode);
        self::assertSame('alive', $frame->payload);
    }

    public function testPayloadLengthEmpty(): void
    {
        $frame = Frame::text('');
        self::assertSame(0, $frame->payloadLength());
    }

    public function testMaskedProperty(): void
    {
        $unmasked = new Frame(true, Opcode::Text, 'data', masked: false);
        $masked = new Frame(true, Opcode::Text, 'data', masked: true);

        self::assertFalse($unmasked->masked);
        self::assertTrue($masked->masked);
    }
}
