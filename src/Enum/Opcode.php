<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Enum;

/**
 * The frame opcodes of RFC 6455 §5.2: continuation, text and binary carry
 * messages; close, ping and pong (0x8 and above) control the connection.
 * The enum is backed by the opcode because the value is fixed by the wire
 * format and the IANA registry (errata E-003): tryFrom() reads a frame
 * header without a lookup table in static state.
 *
 * ARFA 1.3: P1 (immutable cases). Spec V4.3.3: backed enum with a
 * documented external contract (E-003).
 *
 * Preconditions: none.
 *
 * Thread-safety: enum cases are immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     Opcode::tryFrom(0x9)?->isControl();  // true (Ping)
 *     Opcode::tryFrom(0x3);                // null: reserved
 *
 * References: RFC 6455 §5.2, §5.5, §5.6, §11.8 (opcode registry).
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-5.2
 */
enum Opcode: int
{
    case Continuation = 0x0;
    case Text = 0x1;
    case Binary = 0x2;
    case Close = 0x8;
    case Ping = 0x9;
    case Pong = 0xA;

    /** Close, Ping and Pong: the opcodes with the most significant bit set. */
    public function isControl(): bool
    {
        return $this->value >= 0x8;
    }
}
