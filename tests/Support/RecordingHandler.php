<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Support;

use KaririCode\WebSocket\Contract\Connection;
use KaririCode\WebSocket\Contract\MessageHandler;
use KaririCode\WebSocket\Message\Message;

/**
 * A message handler that records every event and can run a test's code
 * on open and on message.
 */
final class RecordingHandler implements MessageHandler
{
    /** @var list<string> */
    public private(set) array $events = [];

    /** @var list<Message> */
    public private(set) array $messages = [];

    /** @var list<array{int, string}> */
    public private(set) array $closes = [];

    /** @var list<\Throwable> */
    public private(set) array $errors = [];

    /**
     * @param (\Closure(Connection): void)|null          $onOpen
     * @param (\Closure(Connection, Message): void)|null $onMessage
     */
    public function __construct(
        private readonly ?\Closure $onOpen = null,
        private readonly ?\Closure $onMessage = null,
        private readonly bool $failOnError = false,
        private readonly bool $failOnClose = false,
    ) {}

    #[\Override]
    public function onOpen(Connection $connection): void
    {
        $this->events[] = 'open';

        if ($this->onOpen !== null) {
            ($this->onOpen)($connection);
        }
    }

    #[\Override]
    public function onMessage(Connection $connection, Message $message): void
    {
        $this->events[] = 'message';
        $this->messages[] = $message;

        if ($this->onMessage !== null) {
            ($this->onMessage)($connection, $message);
        }
    }

    #[\Override]
    public function onClose(Connection $connection, int $code, string $reason): void
    {
        $this->events[] = 'close';
        $this->closes[] = [$code, $reason];

        if ($this->failOnClose) {
            throw new \LogicException('onClose failed');
        }
    }

    #[\Override]
    public function onError(Connection $connection, \Throwable $error): void
    {
        $this->events[] = 'error';
        $this->errors[] = $error;

        if ($this->failOnError) {
            throw new \LogicException('onError failed');
        }
    }
}
