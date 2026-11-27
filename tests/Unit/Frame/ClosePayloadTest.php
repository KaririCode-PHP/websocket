<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Frame;

use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Exception\FrameException;
use KaririCode\WebSocket\Exception\WsCode;
use KaririCode\WebSocket\Frame\ClosePayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClosePayload::class)]
#[CoversClass(FrameException::class)]
final class ClosePayloadTest extends TestCase
{
    #[Test]
    public function anEmptyPayloadHasNoStatus(): void
    {
        $close = ClosePayload::parse('');

        self::assertNull($close->code);
        self::assertSame('', $close->reason);
        self::assertSame(1005, $close->status());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validCodes(): iterable
    {
        foreach ([1000, 1001, 1002, 1003, 1007, 1008, 1009, 1010, 1011, 1012, 1013, 1014, 3000, 3999, 4000, 4999] as $code) {
            yield (string) $code => [$code];
        }
    }

    #[Test]
    #[DataProvider('validCodes')]
    public function aValidCodeIsReadWithItsReason(int $code): void
    {
        $close = ClosePayload::parse(pack('n', $code) . 'bye');

        self::assertSame($code, $close->code);
        self::assertSame($code, $close->status());
        self::assertSame('bye', $close->reason);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidCodes(): iterable
    {
        foreach ([0, 999, 1004, 1005, 1006, 1015, 1016, 1100, 2000, 2999, 5000, 65535] as $code) {
            yield (string) $code => [$code];
        }
    }

    #[Test]
    #[DataProvider('invalidCodes')]
    public function aCodeAPeerMayNotSendIsAProtocolError(int $code): void
    {
        self::assertFails(WsCode::InvalidCloseCode, CloseCode::ProtocolError, pack('n', $code));
    }

    #[Test]
    public function aOneBytePayloadIsAProtocolError(): void
    {
        self::assertFails(WsCode::InvalidClosePayload, CloseCode::ProtocolError, "\x03");
    }

    #[Test]
    public function aReasonThatIsNotUtf8IsInvalidPayload(): void
    {
        self::assertFails(WsCode::InvalidCloseReason, CloseCode::InvalidPayload, "\x03\xE8\xCE\xBA\xE1\xBD\xB9\xCF\x83\xCE\xBC\xCE\xB5\xED\xA0\x80edited");
        self::assertSame('κόσμε', ClosePayload::parse("\x03\xE8κόσμε")->reason);
    }

    private static function assertFails(WsCode $code, CloseCode $closeCode, string $payload): void
    {
        try {
            ClosePayload::parse($payload);
            self::fail('the payload was accepted');
        } catch (FrameException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($closeCode, $e->closeCode);
        }
    }
}
