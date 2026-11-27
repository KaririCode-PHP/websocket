<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Server;

use KaririCode\WebSocket\Exception\InvalidConfigurationException;
use KaririCode\WebSocket\Exception\WsCode;
use KaririCode\WebSocket\Server\ServerOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerOptions::class)]
final class ServerOptionsTest extends TestCase
{
    #[Test]
    public function theDefaultsAreConservative(): void
    {
        $options = new ServerOptions();

        self::assertSame(1_048_576, $options->maxPayloadSize);
        self::assertSame(10_485_760, $options->maxMessageSize);
        self::assertSame(16_777_216, $options->maxBufferedOutput);
        self::assertSame(0.2, $options->selectTimeout);
        self::assertSame(5.0, $options->closeTimeout);
        self::assertSame(65_536, $options->readChunk);
    }

    #[Test]
    public function zeroTimeoutsAndOneByteLimitsAreAllowed(): void
    {
        $options = new ServerOptions(1, 1, 1, 0.0, 0.0, 1);

        self::assertSame(1, $options->maxPayloadSize);
        self::assertSame(0.0, $options->closeTimeout);
    }

    #[Test]
    public function limitsBelowOneAndNegativeTimeoutsAreRefused(): void
    {
        foreach ([
            [WsCode::NonPositiveLimit, 'maxPayloadSize', static fn() => new ServerOptions(maxPayloadSize: 0)],
            [WsCode::NonPositiveLimit, 'maxMessageSize', static fn() => new ServerOptions(maxMessageSize: 0)],
            [WsCode::NonPositiveLimit, 'maxBufferedOutput', static fn() => new ServerOptions(maxBufferedOutput: -1)],
            // @phpstan-ignore argument.type (the runtime check is under test)
            [WsCode::NonPositiveLimit, 'readChunk', static fn() => new ServerOptions(readChunk: 0)],
            [WsCode::NegativeTimeout, 'selectTimeout', static fn() => new ServerOptions(selectTimeout: -0.1)],
            [WsCode::NegativeTimeout, 'closeTimeout', static fn() => new ServerOptions(closeTimeout: -1.0)],
        ] as [$code, $name, $build]) {
            try {
                $build();
                self::fail($name . ' was accepted');
            } catch (InvalidConfigurationException $e) {
                self::assertSame($code, $e->errorCode);
                self::assertStringContainsString('"' . $name . '"', $e->getMessage());
            }
        }
    }
}
