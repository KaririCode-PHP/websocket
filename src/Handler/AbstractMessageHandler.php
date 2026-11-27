<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Handler;

use KaririCode\WebSocket\Contract\Connection;
use KaririCode\WebSocket\Contract\MessageHandler;
use KaririCode\WebSocket\Message\Message;

/**
 * No-op base handler — override only the events you need.
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): provides the terminal
 * stage of the data pipeline with safe defaults. Subclasses override
 * individual events without implementing the full
 * {@see MessageHandler} contract.
 *
 * Spec V4.0: abstract class (not interface default methods) because
 * PHP interfaces do not support default implementations (RFC 6455
 * lifecycle events mapped 1:1 to methods).
 * Single level of inheritance — no further subclassing expected.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-6 Sending and Receiving Data
 */
abstract class AbstractMessageHandler implements MessageHandler
{
    public function onOpen(Connection $connection): void
    {
    }

    public function onMessage(Connection $connection, Message $message): void
    {
    }

    public function onClose(Connection $connection, int $code, string $reason): void
    {
    }

    public function onError(Connection $connection, \Throwable $error): void
    {
    }
}

