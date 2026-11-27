<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Enum;

use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\ConnectionState;
use KaririCode\WebSocket\Enum\Opcode;
use PHPUnit\Framework\TestCase;

final class OpcodeTest extends TestCase
{
    public function testDataOpcodes(): void
    {
        self::assertSame(0x0, Opcode::Continuation->byteValue());
        self::assertSame(0x1, Opcode::Text->byteValue());
        self::assertSame(0x2, Opcode::Binary->byteValue());
    }

    public function testControlOpcodes(): void
    {
        self::assertSame(0x8, Opcode::Close->byteValue());
        self::assertSame(0x9, Opcode::Ping->byteValue());
        self::assertSame(0xA, Opcode::Pong->byteValue());
    }

    public function testIsControl(): void
    {
        self::assertTrue(Opcode::Close->isControl());
        self::assertTrue(Opcode::Ping->isControl());
        self::assertTrue(Opcode::Pong->isControl());
        self::assertFalse(Opcode::Text->isControl());
        self::assertFalse(Opcode::Binary->isControl());
        self::assertFalse(Opcode::Continuation->isControl());
    }

    public function testIsData(): void
    {
        self::assertTrue(Opcode::Text->isData());
        self::assertTrue(Opcode::Binary->isData());
        self::assertTrue(Opcode::Continuation->isData());
        self::assertFalse(Opcode::Close->isData());
    }

    public function testTryFromValue(): void
    {
        self::assertSame(Opcode::Text, Opcode::tryFromByteValue(0x1));
        self::assertSame(Opcode::Close, Opcode::tryFromByteValue(0x8));
        self::assertNull(Opcode::tryFromByteValue(0x3));
        self::assertNull(Opcode::tryFromByteValue(0xFF));
    }
}

final class CloseCodeTest extends TestCase
{
    public function testStandardCodes(): void
    {
        self::assertSame(1000, CloseCode::NormalClosure->numericCode());
        self::assertSame(1001, CloseCode::GoingAway->numericCode());
        self::assertSame(1002, CloseCode::ProtocolError->numericCode());
        self::assertSame(1007, CloseCode::InvalidPayload->numericCode());
        self::assertSame(1009, CloseCode::MessageTooBig->numericCode());
    }

    public function testReasonPhrases(): void
    {
        self::assertSame('Normal Closure', CloseCode::NormalClosure->reason());
        self::assertSame('Protocol Error', CloseCode::ProtocolError->reason());
    }

    public function testSendability(): void
    {
        // Sendable codes
        self::assertTrue(CloseCode::NormalClosure->isSendable());
        self::assertTrue(CloseCode::GoingAway->isSendable());
        self::assertTrue(CloseCode::ProtocolError->isSendable());

        // RFC 6455 §7.4.1: MUST NOT be sent
        self::assertFalse(CloseCode::NoStatusReceived->isSendable());
        self::assertFalse(CloseCode::AbnormalClosure->isSendable());
        self::assertFalse(CloseCode::TlsHandshake->isSendable());
    }

    public function testTryFromValue(): void
    {
        self::assertSame(CloseCode::NormalClosure, CloseCode::tryFromCode(1000));
        self::assertSame(CloseCode::InternalError, CloseCode::tryFromCode(1011));
        self::assertNull(CloseCode::tryFromCode(9999));
    }
}

final class ConnectionStateTest extends TestCase
{
    public function testCanSendData(): void
    {
        self::assertTrue(ConnectionState::Open->canSendData());
        self::assertFalse(ConnectionState::Connecting->canSendData());
        self::assertFalse(ConnectionState::Closing->canSendData());
        self::assertFalse(ConnectionState::Closed->canSendData());
    }

    public function testCanReceive(): void
    {
        self::assertTrue(ConnectionState::Open->canReceive());
        self::assertTrue(ConnectionState::Closing->canReceive());
        self::assertFalse(ConnectionState::Connecting->canReceive());
        self::assertFalse(ConnectionState::Closed->canReceive());
    }

    public function testIsTerminal(): void
    {
        self::assertTrue(ConnectionState::Closed->isTerminal());
        self::assertFalse(ConnectionState::Open->isTerminal());
        self::assertFalse(ConnectionState::Closing->isTerminal());
    }
}
