<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Message;

use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Message\Message;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testTextFactory(): void
    {
        $msg = Message::text('Hello');

        self::assertSame(Opcode::Text, $msg->opcode);
        self::assertSame('Hello', $msg->payload);
        self::assertTrue($msg->isText());
        self::assertFalse($msg->isBinary());
        self::assertSame(5, $msg->length());
    }

    public function testBinaryFactory(): void
    {
        $msg = Message::binary("\x00\xFF");

        self::assertSame(Opcode::Binary, $msg->opcode);
        self::assertSame("\x00\xFF", $msg->payload);
        self::assertTrue($msg->isBinary());
        self::assertFalse($msg->isText());
        self::assertSame(2, $msg->length());
    }

    public function testEmptyPayload(): void
    {
        $msg = Message::text('');

        self::assertSame(0, $msg->length());
        self::assertSame('', $msg->payload);
    }

    public function testControlOpcodeMessage(): void
    {
        $msg = new Message(Opcode::Ping, 'alive');

        self::assertSame(Opcode::Ping, $msg->opcode);
        self::assertFalse($msg->isText());
        self::assertFalse($msg->isBinary());
    }

    public function testReadonlyProperties(): void
    {
        $msg = Message::text('immutable');

        // Verify readonly — should not be assignable
        $ref = new \ReflectionProperty($msg, 'payload');
        self::assertTrue($ref->isReadOnly());
    }
}
