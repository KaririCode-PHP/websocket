<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Exception;

use KaririCode\Contract\Exception\Configuration;
use KaririCode\Contract\Exception\Protocol;
use KaririCode\Contract\Exception\Runtime;
use KaririCode\Contract\Exception\Validation;
use KaririCode\Exception\CodeBlock;
use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Exception\ConnectionException;
use KaririCode\WebSocket\Exception\FrameException;
use KaririCode\WebSocket\Exception\HandshakeException;
use KaririCode\WebSocket\Exception\InvalidConfigurationException;
use KaririCode\WebSocket\Exception\InvalidMessageException;
use KaririCode\WebSocket\Exception\UnsupportedRequestException;
use KaririCode\WebSocket\Exception\WsCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WsCode::class)]
#[CoversClass(FrameException::class)]
#[CoversClass(HandshakeException::class)]
#[CoversClass(InvalidMessageException::class)]
#[CoversClass(ConnectionException::class)]
#[CoversClass(InvalidConfigurationException::class)]
#[CoversClass(UnsupportedRequestException::class)]
final class ExceptionCatalogueTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): \Throwable, WsCode, class-string, string}>
     */
    public static function factories(): iterable
    {
        yield 'reserved opcode' => [static fn() => FrameException::reservedOpcode(), WsCode::ReservedOpcode, Protocol::class, 'reserved opcode'];
        yield 'reserved bits' => [static fn() => FrameException::reservedBits(), WsCode::ReservedBits, Protocol::class, 'RSV bits'];
        yield 'unmasked client frame' => [static fn() => FrameException::unmaskedClientFrame(), WsCode::UnmaskedClientFrame, Protocol::class, 'must be masked'];
        yield 'control frame too large' => [static fn() => FrameException::controlFrameTooLarge(), WsCode::ControlFrameTooLarge, Protocol::class, 'at most 125 bytes'];
        yield 'fragmented control frame' => [static fn() => FrameException::fragmentedControlFrame(), WsCode::FragmentedControlFrame, Protocol::class, 'must not be fragmented'];
        yield 'frame too large' => [static fn() => FrameException::frameTooLarge(), WsCode::FrameTooLarge, Protocol::class, 'frame payload exceeds'];
        yield 'non-minimal length' => [static fn() => FrameException::nonMinimalLength(), WsCode::NonMinimalLength, Protocol::class, 'shortest encoding'];
        yield 'length out of range' => [static fn() => FrameException::lengthOutOfRange(), WsCode::LengthOutOfRange, Protocol::class, 'most significant bit'];
        yield 'message too large' => [static fn() => FrameException::messageTooLarge(), WsCode::MessageTooLarge, Protocol::class, 'message exceeds'];
        yield 'unexpected continuation' => [static fn() => FrameException::unexpectedContinuation(), WsCode::UnexpectedContinuation, Protocol::class, 'without a message'];
        yield 'interleaved data frame' => [static fn() => FrameException::interleavedDataFrame(), WsCode::InterleavedDataFrame, Protocol::class, 'before the fragmented message ended'];
        yield 'invalid UTF-8' => [static fn() => FrameException::invalidUtf8(), WsCode::InvalidUtf8, Protocol::class, 'valid UTF-8'];
        yield 'invalid close payload' => [static fn() => FrameException::invalidClosePayload(), WsCode::InvalidClosePayload, Protocol::class, '2-byte status code'];
        yield 'invalid close code' => [static fn() => FrameException::invalidCloseCode(), WsCode::InvalidCloseCode, Protocol::class, 'may not send'];
        yield 'invalid close reason' => [static fn() => FrameException::invalidCloseReason(), WsCode::InvalidCloseReason, Protocol::class, 'reason of a Close frame'];
        yield 'handshake too large' => [static fn() => HandshakeException::tooLarge(), WsCode::HandshakeTooLarge, Protocol::class, 'exceeds the configured limit'];
        yield 'malformed request line' => [static fn() => HandshakeException::malformedRequestLine(), WsCode::MalformedRequestLine, Protocol::class, 'request line'];
        yield 'method not GET' => [static fn() => HandshakeException::methodNotGet(), WsCode::MethodNotGet, Protocol::class, 'must be GET'];
        yield 'HTTP version' => [static fn() => HandshakeException::unsupportedHttpVersion(), WsCode::UnsupportedHttpVersion, Protocol::class, 'HTTP/1.1'];
        yield 'malformed header' => [static fn() => HandshakeException::malformedHeader(), WsCode::MalformedHeader, Protocol::class, 'HTTP grammar'];
        yield 'missing Host' => [static fn() => HandshakeException::missingHost(), WsCode::MissingHost, Protocol::class, 'Host header'];
        yield 'missing Upgrade' => [static fn() => HandshakeException::missingUpgrade(), WsCode::MissingUpgrade, Protocol::class, '"websocket"'];
        yield 'missing Connection' => [static fn() => HandshakeException::missingConnectionUpgrade(), WsCode::MissingConnectionUpgrade, Protocol::class, '"Upgrade"'];
        yield 'version' => [static fn() => HandshakeException::unsupportedVersion(), WsCode::UnsupportedVersion, Protocol::class, 'must be 13'];
        yield 'key' => [static fn() => HandshakeException::invalidKey(), WsCode::InvalidKey, Protocol::class, '16 bytes in base64'];
        yield 'origin' => [static fn() => HandshakeException::originRejected(), WsCode::OriginRejected, Protocol::class, 'not allowed'];
        yield 'duplicate header' => [static fn() => HandshakeException::duplicateHeader('host'), WsCode::DuplicateHeader, Protocol::class, 'header "host" may appear only once'];
        yield 'text not UTF-8' => [static fn() => InvalidMessageException::textNotUtf8(), WsCode::TextNotUtf8, Validation::class, 'valid UTF-8'];
        yield 'close reason too long' => [static fn() => InvalidMessageException::closeReasonTooLong(), WsCode::CloseReasonTooLong, Validation::class, '123 bytes'];
        yield 'close reason not UTF-8' => [static fn() => InvalidMessageException::closeReasonNotUtf8(), WsCode::CloseReasonNotUtf8, Validation::class, 'valid UTF-8'];
        yield 'unsendable close code' => [static fn() => InvalidMessageException::unsendableCloseCode(1005), WsCode::UnsendableCloseCode, Validation::class, 'Close code 1005 may not be sent'];
        yield 'control payload too large' => [static fn() => InvalidMessageException::controlPayloadTooLarge(), WsCode::ControlPayloadTooLarge, Validation::class, 'at most 125 bytes'];
        yield 'control opcode in message' => [static fn() => InvalidMessageException::controlOpcodeInMessage(), WsCode::ControlOpcodeInMessage, Validation::class, 'not part of a message'];
        yield 'not open' => [static fn() => ConnectionException::notOpen(), WsCode::NotOpen, Runtime::class, 'closing or closed'];
        yield 'bind failed' => [static fn() => ConnectionException::bindFailed('tcp://0.0.0.0:80'), WsCode::BindFailed, Runtime::class, 'listen on "tcp://0.0.0.0:80"'];
        yield 'non-positive limit' => [static fn() => InvalidConfigurationException::nonPositiveLimit('maxPayloadSize'), WsCode::NonPositiveLimit, Configuration::class, '"maxPayloadSize" must be at least 1'];
        yield 'negative timeout' => [static fn() => InvalidConfigurationException::negativeTimeout('closeTimeout'), WsCode::NegativeTimeout, Configuration::class, '"closeTimeout" must be zero or more'];
        yield 'invalid origin' => [static fn() => InvalidConfigurationException::invalidOrigin('null'), WsCode::InvalidOrigin, Configuration::class, '"null" is not scheme://host[:port]'];
        yield 'invalid subprotocol' => [static fn() => InvalidConfigurationException::invalidSubprotocol('a b'), WsCode::InvalidSubprotocol, Configuration::class, '"a b" is not an HTTP token'];
        yield 'not a connection' => [static fn() => UnsupportedRequestException::notAConnection(), WsCode::NotAConnection, Validation::class, 'canNormalize() first'];
    }

    /**
     * @param \Closure(): \Throwable $factory
     * @param class-string           $family
     */
    #[Test]
    #[DataProvider('factories')]
    public function everyFactoryCarriesItsCodeFamilyAndPrefixedMessage(\Closure $factory, WsCode $code, string $family, string $message): void
    {
        $exception = $factory();

        self::assertInstanceOf($family, $exception);
        self::assertSame($code->value, $exception->getCode());
        self::assertTrue(property_exists($exception, 'errorCode'));
        self::assertSame($code, $exception->errorCode);
        self::assertStringStartsWith('[WS_' . $code->value . '] ', $exception->getMessage());
        self::assertStringContainsString($message, $exception->getMessage());
    }

    #[Test]
    public function everyCodeHasAFactoryAndSitsInTheWsBlock(): void
    {
        $used = array_map(static fn(array $row): WsCode => $row[1], iterator_to_array(self::factories()));

        self::assertEqualsCanonicalizing(WsCode::cases(), array_values($used), 'one factory per code, no orphan codes');
        self::assertSame('WS', WsCode::prefix());
        self::assertEquals(new CodeBlock(1800, 1899), WsCode::block());

        foreach (WsCode::cases() as $code) {
            self::assertTrue(WsCode::block()->contains($code->value), $code->name);
        }
    }

    #[Test]
    public function frameFailuresCarryTheCloseCodeToSend(): void
    {
        self::assertSame(CloseCode::ProtocolError, FrameException::reservedOpcode()->closeCode);
        self::assertSame(CloseCode::MessageTooBig, FrameException::frameTooLarge()->closeCode);
        self::assertSame(CloseCode::MessageTooBig, FrameException::messageTooLarge()->closeCode);
        self::assertSame(CloseCode::InvalidPayload, FrameException::invalidUtf8()->closeCode);
        self::assertSame(CloseCode::InvalidPayload, FrameException::invalidCloseReason()->closeCode);
        self::assertSame(CloseCode::ProtocolError, FrameException::invalidCloseCode()->closeCode);
    }

    #[Test]
    public function handshakeFailuresCarryTheStatusToAnswer(): void
    {
        self::assertSame(431, HandshakeException::tooLarge()->status);
        self::assertSame(426, HandshakeException::unsupportedVersion()->status);
        self::assertSame(403, HandshakeException::originRejected()->status);
        self::assertSame(400, HandshakeException::invalidKey()->status);
    }

    #[Test]
    public function quotedIdentifiersCannotBreakTheLogLine(): void
    {
        $message = HandshakeException::duplicateHeader("x\r\nInjected: 1")->getMessage();

        self::assertStringNotContainsString("\n", $message);
        self::assertStringContainsString('"x\r\nInjected: 1"', $message);
    }
}
