<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Frame;

use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\FrameException;
use KaririCode\WebSocket\Exception\WebSocketException;
use KaririCode\WebSocket\Frame\Frame;
use KaririCode\WebSocket\Frame\MessageAssembler;
use PHPUnit\Framework\TestCase;

final class MessageAssemblerTest extends TestCase
{
    private MessageAssembler $assembler;

    protected function setUp(): void
    {
        $this->assembler = new MessageAssembler(maxMessageSize: 1024);
    }

    // ── Single-frame messages ────────────────────────────────────────────

    public function testSingleTextFrame(): void
    {
        $frame = new Frame(fin: true, opcode: Opcode::Text, payload: 'Hello');
        $message = $this->assembler->feed($frame);

        self::assertNotNull($message);
        self::assertTrue($message->isText());
        self::assertSame('Hello', $message->payload);
    }

    public function testSingleBinaryFrame(): void
    {
        $frame = new Frame(fin: true, opcode: Opcode::Binary, payload: "\x00\x01");
        $message = $this->assembler->feed($frame);

        self::assertNotNull($message);
        self::assertTrue($message->isBinary());
        self::assertSame("\x00\x01", $message->payload);
    }

    public function testEmptyTextFrame(): void
    {
        $frame = new Frame(fin: true, opcode: Opcode::Text, payload: '');
        $message = $this->assembler->feed($frame);

        self::assertNotNull($message);
        self::assertSame('', $message->payload);
    }

    // ── Fragmented messages ──────────────────────────────────────────────

    public function testTwoFragmentMessage(): void
    {
        $frag1 = new Frame(fin: false, opcode: Opcode::Text, payload: 'Hel');
        $frag2 = new Frame(fin: true, opcode: Opcode::Continuation, payload: 'lo');

        self::assertNull($this->assembler->feed($frag1));
        self::assertTrue($this->assembler->isFragmenting());

        $message = $this->assembler->feed($frag2);
        self::assertNotNull($message);
        self::assertSame('Hello', $message->payload);
        self::assertTrue($message->isText());
        self::assertFalse($this->assembler->isFragmenting());
    }

    public function testThreeFragmentMessage(): void
    {
        $frag1 = new Frame(fin: false, opcode: Opcode::Binary, payload: 'AA');
        $frag2 = new Frame(fin: false, opcode: Opcode::Continuation, payload: 'BB');
        $frag3 = new Frame(fin: true, opcode: Opcode::Continuation, payload: 'CC');

        self::assertNull($this->assembler->feed($frag1));
        self::assertNull($this->assembler->feed($frag2));

        $message = $this->assembler->feed($frag3);
        self::assertNotNull($message);
        self::assertSame('AABBCC', $message->payload);
        self::assertTrue($message->isBinary());
    }

    // ── Control frames interleaved ───────────────────────────────────────

    public function testControlFrameDuringFragmentation(): void
    {
        $frag1 = new Frame(fin: false, opcode: Opcode::Text, payload: 'Hel');

        // Ping interleaved
        $ping = new Frame(fin: true, opcode: Opcode::Ping, payload: 'alive');

        $frag2 = new Frame(fin: true, opcode: Opcode::Continuation, payload: 'lo');

        self::assertNull($this->assembler->feed($frag1));

        // Ping is returned immediately
        $pingMsg = $this->assembler->feed($ping);
        self::assertNotNull($pingMsg);
        self::assertSame(Opcode::Ping, $pingMsg->opcode);
        self::assertSame('alive', $pingMsg->payload);
        self::assertTrue($this->assembler->isFragmenting());

        // Fragmentation continues
        $message = $this->assembler->feed($frag2);
        self::assertNotNull($message);
        self::assertSame('Hello', $message->payload);
    }

    public function testCloseFrameReturnedImmediately(): void
    {
        $close = new Frame(fin: true, opcode: Opcode::Close, payload: pack('n', 1000));
        $message = $this->assembler->feed($close);

        self::assertNotNull($message);
        self::assertSame(Opcode::Close, $message->opcode);
    }

    // ── Error conditions ─────────────────────────────────────────────────

    public function testNewDataFrameDuringFragmentationThrows(): void
    {
        $frag1 = new Frame(fin: false, opcode: Opcode::Text, payload: 'start');
        $this->assembler->feed($frag1);

        // New text frame before continuation completed
        $illegal = new Frame(fin: true, opcode: Opcode::Text, payload: 'oops');

        $this->expectException(WebSocketException::class);
        $this->assembler->feed($illegal);
    }

    public function testContinuationWithoutPrecedingDataThrows(): void
    {
        $orphan = new Frame(fin: true, opcode: Opcode::Continuation, payload: 'data');

        $this->expectException(WebSocketException::class);
        $this->assembler->feed($orphan);
    }

    public function testMessageTooLargeThrows(): void
    {
        $smallAssembler = new MessageAssembler(maxMessageSize: 10);

        $frag1 = new Frame(fin: false, opcode: Opcode::Text, payload: str_repeat('A', 8));
        $smallAssembler->feed($frag1);

        $frag2 = new Frame(fin: true, opcode: Opcode::Continuation, payload: str_repeat('B', 8));

        $this->expectException(FrameException::class);
        $smallAssembler->feed($frag2);
    }

    public function testInvalidUtf8InTextFrameThrows(): void
    {
        $frame = new Frame(fin: true, opcode: Opcode::Text, payload: "\xFF\xFE");

        $this->expectException(FrameException::class);
        $this->assembler->feed($frame);
    }

    public function testInvalidUtf8InFragmentedTextThrows(): void
    {
        $frag1 = new Frame(fin: false, opcode: Opcode::Text, payload: 'valid');
        $this->assembler->feed($frag1);

        $frag2 = new Frame(fin: true, opcode: Opcode::Continuation, payload: "\xFF\xFE");

        $this->expectException(FrameException::class);
        $this->assembler->feed($frag2);
    }

    public function testBinaryFrameSkipsUtf8Validation(): void
    {
        $frame = new Frame(fin: true, opcode: Opcode::Binary, payload: "\xFF\xFE");
        $message = $this->assembler->feed($frame);

        self::assertNotNull($message);
        self::assertSame("\xFF\xFE", $message->payload);
    }

    // ── Reset ────────────────────────────────────────────────────────────

    public function testResetClearsFragmentState(): void
    {
        $frag = new Frame(fin: false, opcode: Opcode::Text, payload: 'partial');
        $this->assembler->feed($frag);
        self::assertTrue($this->assembler->isFragmenting());

        $this->assembler->reset();
        self::assertFalse($this->assembler->isFragmenting());
    }

    public function testMultipleMessagesSequentially(): void
    {
        $msg1 = new Frame(fin: true, opcode: Opcode::Text, payload: 'first');
        $msg2 = new Frame(fin: true, opcode: Opcode::Text, payload: 'second');

        $result1 = $this->assembler->feed($msg1);
        $result2 = $this->assembler->feed($msg2);

        self::assertSame('first', $result1->payload);
        self::assertSame('second', $result2->payload);
    }
}
