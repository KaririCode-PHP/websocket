<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Frame;

use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Frame\Frame;
use KaririCode\WebSocket\Frame\FrameWriter;
use PHPUnit\Framework\TestCase;

final class FrameWriterTest extends TestCase
{
    private FrameWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new FrameWriter();
    }

    public function testWriteSmallTextFrame(): void
    {
        $frame = Frame::text('Hello');
        $bytes = $this->writer->write($frame);

        // Byte 0: FIN(1) + opcode(0x1) = 0x81
        self::assertSame(0x81, ord($bytes[0]));
        // Byte 1: MASK(0) + length(5) = 0x05
        self::assertSame(0x05, ord($bytes[1]));
        // Payload
        self::assertSame('Hello', substr($bytes, 2));
        self::assertSame(7, strlen($bytes));
    }

    public function testWriteBinaryFrame(): void
    {
        $payload = "\x00\x01\x02\x03";
        $frame = Frame::binary($payload);
        $bytes = $this->writer->write($frame);

        self::assertSame(0x82, ord($bytes[0]));
        self::assertSame(4, ord($bytes[1]));
        self::assertSame($payload, substr($bytes, 2));
    }

    public function testWritePingFrame(): void
    {
        $frame = Frame::ping('heartbeat');
        $bytes = $this->writer->write($frame);

        self::assertSame(0x89, ord($bytes[0]));
        self::assertSame(9, ord($bytes[1]));
        self::assertSame('heartbeat', substr($bytes, 2));
    }

    public function testWritePongFrame(): void
    {
        $frame = Frame::pong('heartbeat');
        $bytes = $this->writer->write($frame);

        self::assertSame(0x8A, ord($bytes[0]));
    }

    public function testWriteCloseFrame(): void
    {
        $frame = Frame::close(1000, 'bye');
        $bytes = $this->writer->write($frame);

        self::assertSame(0x88, ord($bytes[0]));
        // Payload: 2 bytes code + 3 bytes reason = 5
        self::assertSame(5, ord($bytes[1]));
        self::assertSame(1000, unpack('n', substr($bytes, 2, 2))[1]);
        self::assertSame('bye', substr($bytes, 4));
    }

    public function testWriteNonFinFrame(): void
    {
        $frame = new Frame(fin: false, opcode: Opcode::Text, payload: 'chunk');
        $bytes = $this->writer->write($frame);

        // FIN=0 + opcode 0x1 = 0x01
        self::assertSame(0x01, ord($bytes[0]));
    }

    public function testWrite16BitExtendedLength(): void
    {
        $payload = str_repeat('A', 300);
        $frame = Frame::text($payload);
        $bytes = $this->writer->write($frame);

        // Byte 1: 126 (16-bit marker)
        self::assertSame(126, ord($bytes[1]));
        // Bytes 2-3: actual length
        self::assertSame(300, unpack('n', substr($bytes, 2, 2))[1]);
        // Total: 2 + 2 + 300
        self::assertSame(304, strlen($bytes));
    }

    public function testWrite64BitExtendedLength(): void
    {
        $payload = str_repeat('B', 65536);
        $frame = Frame::text($payload);
        $bytes = $this->writer->write($frame);

        // Byte 1: 127 (64-bit marker)
        self::assertSame(127, ord($bytes[1]));
        // Bytes 2-9: actual length
        self::assertSame(65536, unpack('J', substr($bytes, 2, 8))[1]);
        // Total: 2 + 8 + 65536
        self::assertSame(65546, strlen($bytes));
    }

    public function testWriteEmptyPayload(): void
    {
        $frame = Frame::text('');
        $bytes = $this->writer->write($frame);

        self::assertSame(0x81, ord($bytes[0]));
        self::assertSame(0, ord($bytes[1]));
        self::assertSame(2, strlen($bytes));
    }

    public function testRoundTripWithParser(): void
    {
        $parser = new \KaririCode\WebSocket\Frame\FrameParser(requireMask: false);
        $original = Frame::text('Round trip test!');
        $bytes = $this->writer->write($original);

        $result = $parser->parse($bytes);
        self::assertNotNull($result);

        [$parsed] = $result;
        self::assertTrue($parsed->fin);
        self::assertSame(Opcode::Text, $parsed->opcode);
        self::assertSame('Round trip test!', $parsed->payload);
    }
}
