<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Frame;

use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\FrameException;
use KaririCode\WebSocket\Exception\InvalidConfigurationException;
use KaririCode\WebSocket\Exception\WsCode;
use KaririCode\WebSocket\Frame\Frame;
use KaririCode\WebSocket\Frame\FrameParser;
use KaririCode\WebSocket\Tests\Support\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrameParser::class)]
#[CoversClass(Frame::class)]
#[CoversClass(FrameException::class)]
final class FrameParserTest extends TestCase
{
    // ── Complete frames ──────────────────────────────────────────────────

    #[Test]
    public function aMaskedTextFrameIsUnmasked(): void
    {
        $result = (new FrameParser())->parse(Client::text('Hello'));

        self::assertNotNull($result);
        [$frame, $used] = $result;
        self::assertTrue($frame->fin);
        self::assertSame(Opcode::Text, $frame->opcode);
        self::assertSame('Hello', $frame->payload);
        self::assertTrue($frame->masked);
        self::assertSame(11, $used);
    }

    #[Test]
    public function theRfcExampleIsRead(): void
    {
        // RFC 6455 §5.7: a single-frame masked text message "Hello".
        $result = (new FrameParser())->parse("\x81\x85\x37\xfa\x21\x3d\x7f\x9f\x4d\x51\x58");

        self::assertNotNull($result);
        self::assertSame('Hello', $result[0]->payload);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function lengths(): iterable
    {
        yield 'empty' => [0];
        yield '125, the last 7-bit length' => [125];
        yield '126, the first 16-bit length' => [126];
        yield '65535, the last 16-bit length' => [65535];
        yield '65536, the first 64-bit length' => [65536];
    }

    #[Test]
    #[DataProvider('lengths')]
    public function everyLengthEncodingIsRead(int $length): void
    {
        $payload = str_repeat('a', $length);
        $bytes = Client::frame(0x2, $payload);

        $result = (new FrameParser())->parse($bytes . 'next frame');

        self::assertNotNull($result);
        self::assertSame($payload, $result[0]->payload);
        self::assertSame(strlen($bytes), $result[1], 'the bytes after the frame are left for the next call');
    }

    #[Test]
    public function aLargeSixteenBitLengthIsNotMistakenForA64BitOne(): void
    {
        $payload = str_repeat('b', 40000);

        $result = (new FrameParser())->parse(Client::frame(0x2, $payload));

        self::assertSame($payload, $result[0]->payload ?? null);
    }

    #[Test]
    public function finAndContinuationAreKept(): void
    {
        $result = (new FrameParser())->parse(Client::frame(0x0, 'part', fin: false));

        self::assertNotNull($result);
        self::assertFalse($result[0]->fin);
        self::assertSame(Opcode::Continuation, $result[0]->opcode);
    }

    #[Test]
    public function finIsReadFromTheTopBitAlone(): void
    {
        $parser = new FrameParser();

        self::assertFalse($parser->parse(Client::frame(0x1, 'start', fin: false))[0]->fin ?? true, 'the first fragment of a text message');
        self::assertTrue($parser->parse(Client::frame(0x0, 'end'))[0]->fin ?? false, 'the last fragment');
    }

    #[Test]
    public function withoutTheMaskRequirementBothFormsAreRead(): void
    {
        $parser = new FrameParser(requireMask: false);

        self::assertSame('plain', $parser->parse(Client::frame(0x1, 'plain', masked: false))[0]->payload ?? null);
        self::assertFalse($parser->parse(Client::frame(0x1, 'plain', masked: false))[0]->masked ?? true);
        self::assertSame('masked', $parser->parse(Client::text('masked'))[0]->payload ?? null);
        self::assertSame(1048576, $parser->maxPayloadSize);
    }

    // ── Incomplete frames ────────────────────────────────────────────────

    #[Test]
    public function anIncompleteFrameWaitsForMoreBytes(): void
    {
        $parser = new FrameParser();
        $short = Client::text('Hello');
        $medium = Client::frame(0x2, str_repeat('m', 300));
        $long = Client::frame(0x2, str_repeat('l', 70000));

        foreach ([$short, $medium, $long] as $bytes) {
            for ($cut = 0; $cut < strlen($bytes); $cut += max(1, intdiv(strlen($bytes), 40))) {
                self::assertNull($parser->parse(substr($bytes, 0, $cut)), 'cut at ' . $cut);
            }

            self::assertNull($parser->parse(substr($bytes, 0, -1)));
            self::assertNotNull($parser->parse($bytes));
        }

        foreach ([1, 2, 3, 4, 5, 9, 10, 11, 13] as $cut) {
            self::assertNull($parser->parse(substr($long, 0, $cut)), 'header cut at ' . $cut);
        }
    }

    // ── Header rules ─────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{string, WsCode, CloseCode}>
     */
    public static function violations(): iterable
    {
        yield 'RSV1' => [Client::frame(0x1, 'x', rsv: 4), WsCode::ReservedBits, CloseCode::ProtocolError];
        yield 'RSV2' => [Client::frame(0x1, 'x', rsv: 2), WsCode::ReservedBits, CloseCode::ProtocolError];
        yield 'RSV3' => [Client::frame(0x1, 'x', rsv: 1), WsCode::ReservedBits, CloseCode::ProtocolError];
        yield 'opcode 0x3' => [Client::frame(0x3), WsCode::ReservedOpcode, CloseCode::ProtocolError];
        yield 'opcode 0x7' => [Client::frame(0x7), WsCode::ReservedOpcode, CloseCode::ProtocolError];
        yield 'opcode 0xB' => [Client::frame(0xB), WsCode::ReservedOpcode, CloseCode::ProtocolError];
        yield 'opcode 0xF' => [Client::frame(0xF), WsCode::ReservedOpcode, CloseCode::ProtocolError];
        yield 'unmasked text' => [Client::frame(0x1, 'x', masked: false), WsCode::UnmaskedClientFrame, CloseCode::ProtocolError];
        yield 'unmasked close' => [Client::frame(0x8, '', masked: false), WsCode::UnmaskedClientFrame, CloseCode::ProtocolError];
        yield 'fragmented ping' => [Client::frame(0x9, 'x', fin: false), WsCode::FragmentedControlFrame, CloseCode::ProtocolError];
        yield 'fragmented close' => [Client::frame(0x8, '', fin: false), WsCode::FragmentedControlFrame, CloseCode::ProtocolError];
        yield 'ping of 126 bytes' => [Client::frame(0x9, str_repeat('p', 126)), WsCode::ControlFrameTooLarge, CloseCode::ProtocolError];
        yield 'pong declaring 127' => [substr(Client::frame(0xA, str_repeat('p', 70000)), 0, 2), WsCode::ControlFrameTooLarge, CloseCode::ProtocolError];
        yield '16-bit length of 125' => [Client::frame(0x2, str_repeat('n', 125), length: 126), WsCode::NonMinimalLength, CloseCode::ProtocolError];
        yield '16-bit length of 0' => [Client::frame(0x2, '', length: 126), WsCode::NonMinimalLength, CloseCode::ProtocolError];
        yield '64-bit length of 65535' => [Client::frame(0x2, str_repeat('n', 65535), length: 127), WsCode::NonMinimalLength, CloseCode::ProtocolError];
        yield '64-bit length of 126' => [Client::frame(0x2, str_repeat('n', 126), length: 127), WsCode::NonMinimalLength, CloseCode::ProtocolError];
        yield '64-bit length with the top bit set' => ["\x82\xFF\x80\x00\x00\x00\x00\x00\x00\x05", WsCode::LengthOutOfRange, CloseCode::ProtocolError];
    }

    #[Test]
    #[DataProvider('violations')]
    public function aBrokenRuleFailsWithItsCodeAndCloseCode(string $bytes, WsCode $code, CloseCode $closeCode): void
    {
        try {
            (new FrameParser())->parse($bytes);
            self::fail('the frame was accepted');
        } catch (FrameException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($closeCode, $e->closeCode);
        }
    }

    #[Test]
    public function theLimitIsEnforcedBeforeThePayloadArrives(): void
    {
        $parser = new FrameParser(maxPayloadSize: 100);
        $declared = substr(Client::frame(0x2, str_repeat('x', 70000)), 0, 10);
        $huge = "\x82\xFF\x01\x00\x00\x00\x00\x00\x00\x00";   // 2^56: in range, far above the limit

        self::assertNotNull($parser->parse(Client::frame(0x2, str_repeat('x', 100))));

        foreach ([Client::frame(0x2, str_repeat('x', 101)), $declared, $huge] as $bytes) {
            try {
                $parser->parse($bytes);
                self::fail('a frame above the limit was accepted');
            } catch (FrameException $e) {
                self::assertSame(WsCode::FrameTooLarge, $e->errorCode);
                self::assertSame(CloseCode::MessageTooBig, $e->closeCode);
            }
        }
    }

    #[Test]
    public function aControlFrameWithinTheLimitsIsRead(): void
    {
        $result = (new FrameParser())->parse(Client::frame(0x9, str_repeat('p', 125)));

        self::assertNotNull($result);
        self::assertSame(Opcode::Ping, $result[0]->opcode);
        self::assertSame(125, $result[0]->length());
    }

    #[Test]
    public function theLimitIsAtLeastOneByte(): void
    {
        self::assertSame('a', (new FrameParser(maxPayloadSize: 1))->parse(Client::frame(0x2, 'a'))[0]->payload ?? null);

        $this->expectException(InvalidConfigurationException::class);

        new FrameParser(maxPayloadSize: 0);
    }

    // ── Masking ──────────────────────────────────────────────────────────

    #[Test]
    public function maskingIsItsOwnInverse(): void
    {
        foreach (['', 'a', 'abcd', 'abcde', str_repeat('xyz', 1000)] as $data) {
            $masked = FrameParser::applyMask($data, Client::MASK);

            self::assertSame(Client::mask($data), $masked);
            self::assertSame($data, FrameParser::applyMask($masked, Client::MASK));
            self::assertSame(strlen($data), strlen($masked));
        }
    }
}
