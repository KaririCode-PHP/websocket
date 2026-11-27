<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Contract;

use KaririCode\WebSocket\Enum\CloseCode;
use KaririCode\WebSocket\Enum\ConnectionState;
use KaririCode\WebSocket\Message\Message;

/**
 * Single WebSocket connection with state machine (RFC 6455 §7.1).
 *
 * ARFA 1.3 P1: connection metadata is immutable once established.
 * The mutable state (send, close, state transitions) is inherent
 * to the connection lifecycle per RFC 6455 §7.1.
 *
 * Spec V4.0 §1.3: interface without "Interface" suffix — the contract
 * name matches the domain concept directly.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-4 Opening Handshake
 */
interface Connection
{
    public function id(): string;

    public function state(): ConnectionState;

    public function remoteAddress(): string;

    /**
     * URI path used during the opening handshake (e.g. "/ws/chat/room-42").
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455#section-4 Opening Handshake
     */
    public function path(): string;

    public function send(Message $message): void;

    public function close(CloseCode $code = CloseCode::NormalClosure, string $reason = ''): void;

    /**
     * @return array<string, mixed>
      *
      * @see https://datatracker.ietf.org/doc/html/rfc6455#section-4 Opening Handshake
     */
    public function metadata(): array;

    public function setMetadata(string $key, mixed $value): void;
}

