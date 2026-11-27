<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Frame;

use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\FrameException;
use KaririCode\WebSocket\Frame\FrameParser;
use PHPUnit\Framework\TestCase;

final class FrameParserTest extends TestCase
{
    private FrameParser $parser;

    protected function setUp(): void
    {
        // requireMask=false for simpler test frame construction
        $this->parser = new FrameParser(maxPayloadSize: 65536, requireMask: false);
    }

    public function testParseSingleUnmaskedTextFrame(): void
    {
        // FIN=1, opcode=0x1 (text), MASK=0, length=5, payload="Hello"
        $frame = chr(0x81) . chr(0x05) . 'Hello';

        $result = $this->parser->parse($frame);
        self::assertNotNull($result);

        [$parsed, $consumed] = $result;
        self::assertTrue($parsed->fin);
        self::assertSame(Opcode::Text, $parsed->opcode);
        self::assertSame('Hello', $parsed->payload);
        self::assertSame(7, $consumed);
    }

    public function testParseMaskedFrame(): void
    {
        $maskedParser = new FrameParser(requireMask: true);
        $payload = 'Test';
        $maskKey = "\x37\xfa\x21\x3d";
        $maskedPayload = FrameParser::applyMask($payload, $maskKey);

        // FIN=1, opcode=0x1, MASK=1, length=4
        $frame = chr(0x81) . chr(0x84) . $maskKey . $maskedPayload;

        $result = $maskedParser->parse($frame);
        self::assertNotNull($result);

        [$parsed, $consumed] = $result;
        self::assertSame('Test', $parsed->payload);
        self::assertTrue($parsed->masked);
        self::assertSame(10, $consumed);
    }

    public function testParseBinaryFrame(): void
    {
        $payload = "\x00\x01\x02\x03";
        $frame = chr(0x82) . chr(0x04) . $payload;

        $result = $this->parser->parse($frame);
        self::assertNotNull($result);

        [$parsed] = $result;
        self::assertSame(Opcode::Binary, $parsed->opcode);
        self::assertSame($payload, $parsed->payload);
    }

    public function testParsePingFrame(): void
    {
        $frame = chr(0x89) . chr(0x04) . 'ping';

        [$parsed] = $this->parser->parse($frame);
        self::assertSame(Opcode::Ping, $parsed->opcode);
        self::assertTrue($parsed->fin);
        self::assertSame('ping', $parsed->payload);
    }

    public function testParsePongFrame(): void
    {
        $frame = chr(0x8A) . chr(0x04) . 'pong';

        [$parsed] = $this->parser->parse($frame);
        self::assertSame(Opcode::Pong, $parsed->opcode);
    }

    public function testParseCloseFrame(): void
    {
        // Close with code 1000 + reason
        $payload = pack('n', 1000) . 'Normal';
        $frame = chr(0x88) . chr(strlen($payload)) . $payload;

        [$parsed] = $this->parser->parse($frame);
        self::assertSame(Opcode::Close, $parsed->opcode);
        self::assertSame($payload, $parsed->payload);
    }

    public function testParse16BitExtendedLength(): void
    {
        $payload = str_repeat('A', 300);
        $frame = chr(0x81) . chr(126) . pack('n', 300) . $payload;

        [$parsed, $consumed] = $this->parser->parse($frame);
        self::assertSame(300, $parsed->payloadLength());
        self::assertSame(304, $consumed); // 2 + 2 + 300
    }

    public function testParse64BitExtendedLength(): void
    {
        $payload = str_repeat('B', 65536);
        $frame = chr(0x81) . chr(127) . pack('J', 65536) . $payload;

        [$parsed, $consumed] = $this->parser->parse($frame);
        self::assertSame(65536, $parsed->payloadLength());
        self::assertSame(65546, $consumed); // 2 + 8 + 65536
    }

    public function testIncompleteFrameReturnsNull(): void
    {
        // Only 1 byte — need at least 2
        self::assertNull($this->parser->parse(chr(0x81)));

        // Header says 5 bytes but only 3 available
        self::assertNull($this->parser->parse(chr(0x81) . chr(0x05) . 'He'));
    }

    public function testEmptyBufferReturnsNull(): void
    {
        self::assertNull($this->parser->parse(''));
    }

    public function testReservedOpcodeThrows(): void
    {
        // opcode 0x3 is reserved
        $frame = chr(0x83) . chr(0x00);

        $this->expectException(FrameException::class);
        $this->parser->parse($frame);
    }

    public function testControlFrameTooLargeThrows(): void
    {
        // Ping with 126 bytes payload (exceeds 125 limit)
        $frame = chr(0x89) . chr(126) . pack('n', 126) . str_repeat('X', 126);

        $this->expectException(FrameException::class);
        $this->parser->parse($frame);
    }

    public function testFragmentedControlFrameThrows(): void
    {
        // Ping with FIN=0 (fragmentation not allowed for control)
        $frame = chr(0x09) . chr(0x04) . 'ping';

        $this->expectException(FrameException::class);
        $this->parser->parse($frame);
    }

    public function testPayloadTooLargeThrows(): void
    {
        $smallParser = new FrameParser(maxPayloadSize: 10, requireMask: false);
        $frame = chr(0x81) . chr(0x14) . str_repeat('X', 20);

        $this->expectException(FrameException::class);
        $smallParser->parse($frame);
    }

    public function testMaskRequired(): void
    {
        $strictParser = new FrameParser(requireMask: true);
        // Unmasked frame
        $frame = chr(0x81) . chr(0x05) . 'Hello';

        $this->expectException(FrameException::class);
        $strictParser->parse($frame);
    }

    public function testApplyMaskIsSelfInverse(): void
    {
        $data = 'Hello, WebSocket!';
        $key = "\xAB\xCD\xEF\x01";

        $masked = FrameParser::applyMask($data, $key);
        self::assertNotSame($data, $masked);

        $unmasked = FrameParser::applyMask($masked, $key);
        self::assertSame($data, $unmasked);
    }

    public function testParseFragmentedFrames(): void
    {
        // First fragment: FIN=0, opcode=Text
        $frame1 = chr(0x01) . chr(0x03) . 'Hel';
        // Continuation: FIN=0, opcode=Continuation
        $frame2 = chr(0x00) . chr(0x02) . 'lo';
        // Final: FIN=1, opcode=Continuation
        $frame3 = chr(0x80) . chr(0x01) . '!';

        [$parsed1] = $this->parser->parse($frame1);
        self::assertFalse($parsed1->fin);
        self::assertSame(Opcode::Text, $parsed1->opcode);

        [$parsed2] = $this->parser->parse($frame2);
        self::assertFalse($parsed2->fin);
        self::assertSame(Opcode::Continuation, $parsed2->opcode);

        [$parsed3] = $this->parser->parse($frame3);
        self::assertTrue($parsed3->fin);
        self::assertSame(Opcode::Continuation, $parsed3->opcode);
    }

    public function testNonZeroRsvThrows(): void
    {
        // RSV1 set: byte0 = 0xC1 (FIN + RSV1 + Text)
        $frame = chr(0xC1) . chr(0x01) . 'X';

        $this->expectException(\KaririCode\WebSocket\Exception\WebSocketException::class);
        $this->parser->parse($frame);
    }
}
