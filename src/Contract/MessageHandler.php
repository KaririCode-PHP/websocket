<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Contract;

use KaririCode\WebSocket\Message\Message;

/**
 * Handler for WebSocket lifecycle events (RFC 6455 §7.1).
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): the terminal stage of the
 * server's data pipeline. Incoming bytes flow through FrameParser →
 * MessageAssembler → this handler, each stage consuming immutable
 * value objects.
 *
 * Spec V4.0 §1.3: interface without "Interface" suffix.
 *
 * Implementations react to connection, message, and disconnection
 * events. Use {@see AbstractMessageHandler} for no-op defaults.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-6 Sending and Receiving Data
 */
interface MessageHandler
{
    public function onOpen(Connection $connection): void;

    public function onMessage(Connection $connection, Message $message): void;

    public function onClose(Connection $connection, int $code, string $reason): void;

    public function onError(Connection $connection, \Throwable $error): void;
}

