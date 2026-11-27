<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Server;

/**
 * Runs a socket call with PHP's warnings silenced, so the caller acts on
 * the call's return value (false: a client that left, an address in use)
 * instead of leaving a warning in the log; the `@` operator is not used
 * (Spec V4.3.3 §29.2).
 *
 * ARFA 1.3: P2 (wraps one I/O step). Spec V4.3.3: stateless, final.
 *
 * Preconditions: the closure reports failure through its return value.
 *
 * Thread-safety: installs a handler for the duration of the call and
 * restores the previous one, even when the closure throws.
 *
 * Side effects: those of the closure.
 *
 * Example:
 *
 *     $client = Silently::run(static fn(): mixed => stream_socket_accept($server, 0));
 *     if ($client === false) {
 *         return;   // another worker took it, or the client left
 *     }
 *
 * References: PHP manual, set_error_handler(); KaririCode Spec V4.3.3
 * §29.2.
 *
 * @see https://www.php.net/manual/en/function.set-error-handler.php
 */
final class Silently
{
    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public static function run(\Closure $operation): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
