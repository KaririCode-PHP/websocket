<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Integration;

use KaririCode\WebSocket\Contract\Connection;
use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\ConnectionState;
use KaririCode\WebSocket\Integration\WebSocketNormalizer;
use KaririCode\WebSocket\Message\Message;
use PHPUnit\Framework\TestCase;

final class WebSocketNormalizerTest extends TestCase
{
    private WebSocketNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new WebSocketNormalizer();
    }

    public function testCanNormalizeConnectionInstance(): void
    {
        $connection = $this->createStubConnection('/ws/chat');

        self::assertTrue($this->normalizer->canNormalize($connection));
    }

    public function testCannotNormalizeOtherObjects(): void
    {
        self::assertFalse($this->normalizer->canNormalize(new \stdClass()));
        self::assertFalse($this->normalizer->canNormalize(new \ArrayObject()));
    }

    public function testNormalizeProducesCorrectMethod(): void
    {
        $connection = $this->createStubConnection('/ws/chat');

        $normalized = $this->normalizer->normalize($connection);

        self::assertSame('WEBSOCKET', $normalized->method);
    }

    public function testNormalizeProducesCorrectUri(): void
    {
        $connection = $this->createStubConnection('/ws/chat/room-42');

        $normalized = $this->normalizer->normalize($connection);

        self::assertSame('/ws/chat/room-42', $normalized->uri);
    }

    public function testNormalizeDefaultsEmptyPathToSlash(): void
    {
        $connection = $this->createStubConnection('');

        $normalized = $this->normalizer->normalize($connection);

        self::assertSame('/', $normalized->uri);
    }

    public function testNormalizePreservesOriginal(): void
    {
        $connection = $this->createStubConnection('/ws');

        $normalized = $this->normalizer->normalize($connection);

        self::assertSame($connection, $normalized->original);
    }

    private function createStubConnection(string $path): Connection
    {
        return new class ($path) implements Connection {
            public function __construct(private readonly string $requestPath) {}

            public function id(): string { return 'test-id'; }
            public function state(): ConnectionState { return ConnectionState::Open; }
            public function remoteAddress(): string { return '127.0.0.1'; }
            public function path(): string { return $this->requestPath; }
            public function send(Message $message): void {}
            public function close(CloseCode $code = CloseCode::NormalClosure, string $reason = ''): void {}
            public function metadata(): array { return []; }
            public function setMetadata(string $key, mixed $value): void {}
        };
    }
}
