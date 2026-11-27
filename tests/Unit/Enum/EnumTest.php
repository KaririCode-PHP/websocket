<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Enum;

use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\Opcode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Opcode::class)]
#[CoversClass(CloseCode::class)]
final class EnumTest extends TestCase
{
    #[Test]
    public function opcodesAreTheRegisteredValues(): void
    {
        self::assertSame([0x0, 0x1, 0x2, 0x8, 0x9, 0xA], array_map(static fn(Opcode $o): int => $o->value, Opcode::cases()));
        self::assertSame([false, false, false, true, true, true], array_map(static fn(Opcode $o): bool => $o->isControl(), Opcode::cases()));

        foreach ([0x3, 0x4, 0x5, 0x6, 0x7, 0xB, 0xC, 0xD, 0xE, 0xF] as $reserved) {
            self::assertNull(Opcode::tryFrom($reserved));
        }
    }

    #[Test]
    public function closeCodesAreTheRegisteredValuesWithShortReasons(): void
    {
        self::assertSame(
            [1000, 1001, 1002, 1003, 1005, 1006, 1007, 1008, 1009, 1010, 1011, 1012, 1013, 1014, 1015],
            array_map(static fn(CloseCode $c): int => $c->value, CloseCode::cases()),
        );

        foreach (CloseCode::cases() as $code) {
            self::assertNotSame('', $code->reason());
            self::assertLessThanOrEqual(123, strlen($code->reason()));
        }

        self::assertSame('Message Too Big', CloseCode::MessageTooBig->reason());
        self::assertSame('Internal Error', CloseCode::InternalError->reason());
    }

    #[Test]
    public function validCodesAreTheOnesAPeerMaySend(): void
    {
        $valid = array_filter(range(0, 5100), CloseCode::isValid(...));

        self::assertSame(
            [...range(1000, 1003), ...range(1007, 1014), ...range(3000, 4999)],
            array_values($valid),
        );
        self::assertFalse(CloseCode::isValid(CloseCode::NoStatusReceived->value));
        self::assertFalse(CloseCode::isValid(CloseCode::AbnormalClosure->value));
        self::assertFalse(CloseCode::isValid(CloseCode::TlsHandshake->value));
    }
}
