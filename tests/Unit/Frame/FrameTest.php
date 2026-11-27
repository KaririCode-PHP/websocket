<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Frame;

use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\InvalidMessageException;
use KaririCode\WebSocket\Exception\WsCode;
use KaririCode\WebSocket\Frame\ClosePayload;
use KaririCode\WebSocket\Frame\Frame;
use KaririCode\WebSocket\Frame\FrameWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Frame::class)]
#[CoversClass(FrameWriter::class)]
#[CoversClass(InvalidMessageException::class)]
final class FrameTest extends TestCase
{
    // ── Construction ─────────────────────────────────────────────────────

    #[Test]
    public function factoriesSetTheOpcodeAndFin(): void
    {
        self::assertEquals(new Frame(true, Opcode::Text, 'a'), Frame::text('a'));
        self::assertEquals(new Frame(false, Opcode::Binary, 'b'), Frame::binary('b', fin: false));
        self::assertEquals(new Frame(true, Opcode::Continuation, 'c'), Frame::continuation('c'));
        self::assertEquals(new Frame(true, Opcode::Ping, 'p'), Frame::ping('p'));
        self::assertEquals(new Frame(true, Opcode::Pong, ''), Frame::pong());
        self::assertFalse(Frame::text('a')->masked);
        self::assertSame(3, Frame::binary('abc')->length());
    }

    #[Test]
    public function aTextFragmentMayEndInsideAUtf8Sequence(): void
    {
        self::assertSame("\xC3", Frame::text("\xC3", fin: false)->payload);
    }

    #[Test]
    public function controlFramesAreFinalAndShort(): void
    {
        self::assertSame(125, Frame::ping(str_repeat('p', 125))->length());

        foreach ([static fn(): Frame => Frame::ping(str_repeat('p', 126)), static fn(): Frame => new Frame(false, Opcode::Pong, ''), static fn(): Frame => new Frame(true, Opcode::Close, str_repeat('c', 126))] as $build) {
            self::assertInvalid(WsCode::ControlPayloadTooLarge, $build);
        }

        self::assertSame(126, Frame::text(str_repeat('t', 126))->length(), 'data frames have no such limit');
        self::assertFalse(Frame::binary('', fin: false)->fin);
    }

    // ── Close frames ─────────────────────────────────────────────────────

    #[Test]
    public function aCloseFrameCarriesTheCodeAndReason(): void
    {
        $frame = Frame::close(CloseCode::GoingAway, 'Server restart');

        self::assertSame(Opcode::Close, $frame->opcode);
        self::assertSame("\x03\xE9Server restart", $frame->payload);
        self::assertSame("\x03\xE8", Frame::close()->payload, '1000 by default');
        self::assertSame("\x0F\xA1", Frame::close(4001)->payload, 'an application code');
        self::assertSame(4001, ClosePayload::parse(Frame::close(4001)->payload)->code);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unsendableCodes(): iterable
    {
        foreach ([0, 999, 1004, 1005, 1006, 1015, 1016, 2999, 5000, 65535] as $code) {
            yield (string) $code => [$code];
        }
    }

    #[Test]
    #[DataProvider('unsendableCodes')]
    public function codesAPeerMayNotSendAreRefused(int $code): void
    {
        self::assertInvalid(WsCode::UnsendableCloseCode, static fn(): Frame => Frame::close($code));
    }

    #[Test]
    public function theReasonIsShortUtf8(): void
    {
        self::assertSame(125, Frame::close(1000, str_repeat('r', 123))->length());
        self::assertSame("\x03\xE8Olá", Frame::close(1000, 'Olá')->payload);
        self::assertInvalid(WsCode::CloseReasonTooLong, static fn(): Frame => Frame::close(1000, str_repeat('r', 124)));
        self::assertInvalid(WsCode::CloseReasonNotUtf8, static fn(): Frame => Frame::close(1000, "\xFF"));
    }

    // ── Writing ──────────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function encodings(): iterable
    {
        yield 'empty' => [0, "\x82\x00"];
        yield '125' => [125, "\x82\x7D"];
        yield '126' => [126, "\x82\x7E\x00\x7E"];
        yield '65535' => [65535, "\x82\x7E\xFF\xFF"];
        yield '65536' => [65536, "\x82\x7F\x00\x00\x00\x00\x00\x01\x00\x00"];
    }

    #[Test]
    #[DataProvider('encodings')]
    public function theLengthUsesItsShortestEncoding(int $length, string $header): void
    {
        $payload = str_repeat('z', $length);

        self::assertSame($header . $payload, (new FrameWriter())->write(Frame::binary($payload)));
    }

    #[Test]
    public function finOpcodeAndNoMaskMakeTheFirstBytes(): void
    {
        $writer = new FrameWriter();

        self::assertSame("\x81\x02hi", $writer->write(Frame::text('hi')));
        self::assertSame("\x01\x02hi", $writer->write(Frame::text('hi', fin: false)));
        self::assertSame("\x80\x00", $writer->write(Frame::continuation('')));
        self::assertSame("\x00\x01m", $writer->write(Frame::continuation('m', fin: false)));
        self::assertSame("\x02\x01m", $writer->write(Frame::binary('m', fin: false)));
        self::assertSame("\x89\x01p", $writer->write(Frame::ping('p')));
        self::assertSame("\x8A\x00", $writer->write(Frame::pong()));
        self::assertSame("\x88\x02\x03\xE8", $writer->write(Frame::close()));
        self::assertSame("\x82\x01m", $writer->write(new Frame(true, Opcode::Binary, 'm', masked: true)), 'server frames are never masked');
    }

    private static function assertInvalid(WsCode $code, \Closure $build): void
    {
        try {
            $build();
            self::fail('the frame was built');
        } catch (InvalidMessageException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }
}
