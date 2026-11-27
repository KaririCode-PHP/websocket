<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Enum;

/**
 * WebSocket connection lifecycle states (RFC 6455 §7.1.4).
 *
 * ARFA 1.3 P1 (Immutable State Transformation): immutable value type.
 *
 * Spec V4.0 §2.1: pure enum.
 *
 * FSM transitions:
 *   Connecting → Open → Closing → Closed
 *
 * Only forward transitions are valid. A connection in Closing
 * state may still receive frames but MUST NOT send data frames
 * (RFC 6455 §7.1.1). Query methods encode the RFC rules so callers
 * never need to inspect the raw case.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-7.1.4 Connection States
 */
enum ConnectionState
{
    case Connecting;
    case Open;
    case Closing;
    case Closed;

    public function canSendData(): bool
    {
        return $this === self::Open;
    }

    public function canReceive(): bool
    {
        return $this === self::Open || $this === self::Closing;
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }
}

