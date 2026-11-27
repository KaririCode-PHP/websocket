<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Exception;

use KaririCode\Exception\ValidationException;

/**
 * WebSocketNormalizer::normalize() received an object that is not a
 * WebSocket connection; the router asks canNormalize() first, so this is
 * a wiring error.
 *
 * Preconditions: none.
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     (new WebSocketNormalizer())->normalize(new \stdClass());  // 1870
 *
 * References: kariricode/router RequestNormalizer; ADR-002.
 *
 * @see https://github.com/KaririCode-PHP/router
 */
final class UnsupportedRequestException extends ValidationException
{
    public static function notAConnection(): self
    {
        return new self(WsCode::NotAConnection, 'WebSocketNormalizer::normalize() needs a WebSocket connection; call canNormalize() first.');
    }
}
