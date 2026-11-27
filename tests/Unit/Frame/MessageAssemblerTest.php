<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Frame;

use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Exception\FrameException;
use KaririCode\WebSocket\Exception\InvalidConfigurationException;
use KaririCode\WebSocket\Exception\InvalidMessageException;
use KaririCode\WebSocket\Exception\WsCode;
use KaririCode\WebSocket\Frame\Frame;
use KaririCode\WebSocket\Frame\MessageAssembler;
use KaririCode\WebSocket\Message\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageAssembler::class)]
#[CoversClass(Message::class)]
final class MessageAssemblerTest extends TestCase
{
    #[Test]
    public function aFinalFrameIsAWholeMessage(): void
    {
        $assembler = new MessageAssembler();

        $text = $assembler->feed(Frame::text('hello'));
        $binary = $assembler->feed(Frame::binary("\x00\xFF"));

        self::assertNotNull($text);
        self::assertNotNull($binary);
        self::assertTrue($text->isText());
        self::assertSame('hello', $text->payload);
        self::assertTrue($binary->isBinary());
        self::assertSame("\x00\xFF", $binary->payload);
        self::assertFalse($assembler->isAssembling());
    }

    #[Test]
    public function fragmentsAreJoinedAndUtf8IsCheckedOnTheWhole(): void
    {
        $assembler = new MessageAssembler();

        self::assertNull($assembler->feed(Frame::text("ol\xC3", fin: false)));
        self::assertTrue($assembler->isAssembling());
        self::assertNull($assembler->feed(Frame::continuation('', fin: false)), 'an empty fragment is allowed');
        $message = $assembler->feed(Frame::continuation("\xA1!"));

        self::assertNotNull($message);
        self::assertSame('olá!', $message->payload);
        self::assertTrue($message->isText());
        self::assertFalse($assembler->isAssembling());
        self::assertSame('next', $assembler->feed(Frame::binary('next'))?->payload, 'the next message starts clean');
    }

    #[Test]
    public function aFragmentedBinaryMessageStaysBinary(): void
    {
        $assembler = new MessageAssembler();

        self::assertNull($assembler->feed(Frame::binary('ab', fin: false)));
        $message = $assembler->feed(Frame::continuation('cd'));

        self::assertNotNull($message);
        self::assertTrue($message->isBinary());
        self::assertSame('abcd', $message->payload);
    }

    #[Test]
    public function aContinuationWithoutMessageIsAProtocolError(): void
    {
        self::assertFails(WsCode::UnexpectedContinuation, CloseCode::ProtocolError, new MessageAssembler(), Frame::continuation('x'));
    }

    #[Test]
    public function aNewMessageInsideAnotherIsAProtocolError(): void
    {
        $assembler = new MessageAssembler();
        $assembler->feed(Frame::text('a', fin: false));

        self::assertFails(WsCode::InterleavedDataFrame, CloseCode::ProtocolError, $assembler, Frame::binary('b'));
    }

    #[Test]
    public function theLimitCountsEveryFragment(): void
    {
        $assembler = new MessageAssembler(maxMessageSize: 10);

        self::assertSame(10, $assembler->feed(Frame::binary(str_repeat('x', 10)))?->length());
        self::assertNull($assembler->feed(Frame::binary(str_repeat('x', 6), fin: false)));
        self::assertFails(WsCode::MessageTooLarge, CloseCode::MessageTooBig, $assembler, Frame::continuation(str_repeat('x', 5)));
        self::assertFalse($assembler->isAssembling(), 'the partial message is dropped');
        self::assertFails(WsCode::MessageTooLarge, CloseCode::MessageTooBig, new MessageAssembler(maxMessageSize: 10), Frame::text(str_repeat('t', 11)));
    }

    #[Test]
    public function textThatIsNotUtf8IsInvalidPayload(): void
    {
        $assembler = new MessageAssembler();
        $assembler->feed(Frame::text("\xC3", fin: false));

        self::assertFails(WsCode::InvalidUtf8, CloseCode::InvalidPayload, $assembler, Frame::continuation('x'));
        self::assertFails(WsCode::InvalidUtf8, CloseCode::InvalidPayload, new MessageAssembler(), Frame::text("\xED\xA0\x80"));
        self::assertSame("\xC3", (new MessageAssembler())->feed(Frame::binary("\xC3"))?->payload, 'binary carries any bytes');
    }

    #[Test]
    public function controlFramesAreNotPartOfMessages(): void
    {
        try {
            (new MessageAssembler())->feed(Frame::ping());
            self::fail('a ping was assembled');
        } catch (InvalidMessageException $e) {
            self::assertSame(WsCode::ControlOpcodeInMessage, $e->errorCode);
        }
    }

    #[Test]
    public function resetDropsThePartialMessage(): void
    {
        $assembler = new MessageAssembler();
        $assembler->feed(Frame::text('a', fin: false));
        $assembler->reset();

        self::assertFalse($assembler->isAssembling());
        self::assertSame('b', $assembler->feed(Frame::text('b'))?->payload);
    }

    #[Test]
    public function theLimitDefaultsToTenMebibytesAndIsAtLeastOneByte(): void
    {
        self::assertSame(10_485_760, (new MessageAssembler())->maxMessageSize);
        self::assertSame('x', (new MessageAssembler(maxMessageSize: 1))->feed(Frame::binary('x'))?->payload);

        $this->expectException(InvalidConfigurationException::class);

        new MessageAssembler(maxMessageSize: 0);
    }

    private static function assertFails(WsCode $code, CloseCode $closeCode, MessageAssembler $assembler, Frame $frame): void
    {
        try {
            $assembler->feed($frame);
            self::fail('the frame was assembled');
        } catch (FrameException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($closeCode, $e->closeCode);
        }
    }
}
