<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Message;

use KaririCode\WebSocket\Enum\Opcode;
use KaririCode\WebSocket\Exception\InvalidMessageException;
use KaririCode\WebSocket\Exception\WsCode;
use KaririCode\WebSocket\Message\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Message::class)]
final class MessageTest extends TestCase
{
    #[Test]
    public function textIsUtf8AndBinaryIsAnyBytes(): void
    {
        $text = Message::text('{"name":"São Paulo"}');
        $binary = Message::binary("\x00\x01\xFF");

        self::assertSame(Opcode::Text, $text->opcode);
        self::assertTrue($text->isText());
        self::assertFalse($text->isBinary());
        self::assertSame(21, $text->length());
        self::assertSame(Opcode::Binary, $binary->opcode);
        self::assertTrue($binary->isBinary());
        self::assertFalse($binary->isText());
        self::assertSame("\x00\x01\xFF", $binary->payload);
        self::assertSame('', Message::text('')->payload);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUtf8(): iterable
    {
        yield 'lone continuation byte' => ["\x80"];
        yield 'truncated sequence' => ["\xC3"];
        yield 'overlong encoding' => ["\xC0\xAF"];
        yield 'surrogate half' => ["\xED\xA0\x80"];
        yield 'above U+10FFFF' => ["\xF4\x90\x80\x80"];
        yield 'invalid byte' => ["ok\xFF"];
    }

    #[Test]
    #[DataProvider('invalidUtf8')]
    public function textThatIsNotUtf8IsRefused(string $payload): void
    {
        try {
            Message::text($payload);
            self::fail('invalid UTF-8 was accepted');
        } catch (InvalidMessageException $e) {
            self::assertSame(WsCode::TextNotUtf8, $e->errorCode);
        }

        self::assertSame($payload, Message::binary($payload)->payload);
    }
}
