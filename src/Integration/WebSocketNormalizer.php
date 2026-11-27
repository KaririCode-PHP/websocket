<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Integration;

use KaririCode\Router\Contract\RequestNormalizer;
use KaririCode\Router\Core\NormalizedRequest;
use KaririCode\WebSocket\Contract\Connection;
use KaririCode\WebSocket\Exception\UnsupportedRequestException;

/**
 * Lets kariricode/router route WebSocket connections: a connection becomes
 * a request with the pseudo-method WEBSOCKET and the path its handshake
 * asked for, so `route(['WEBSOCKET'], '/chat/{room}', ...)` picks the
 * handler for a connection and its parameters.
 *
 * ARFA 1.3: P4 (the router stays protocol-agnostic). Spec V4.3.3: final;
 * the `object` parameters are the router's RequestNormalizer signature.
 *
 * Preconditions: normalize() takes what canNormalize() accepted
 * (UnsupportedRequestException 1870 otherwise).
 *
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $routes->route([WebSocketNormalizer::METHOD], '/chat/{room}', ChatRoom::class);
 *     $request = (new WebSocketNormalizer())->normalize($connection);
 *     $match = $routes->match($request->method, $request->uri);
 *
 * References: kariricode/router RequestNormalizer and NormalizedRequest
 * (its ADR-002); RFC 6455 §3 (resource name); ADR-002.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-3
 */
final class WebSocketNormalizer implements RequestNormalizer
{
    public const string METHOD = 'WEBSOCKET';

    #[\Override]
    public function canNormalize(object $request): bool
    {
        return $request instanceof Connection;
    }

    #[\Override]
    public function normalize(object $request): NormalizedRequest
    {
        if (!$request instanceof Connection) {
            throw UnsupportedRequestException::notAConnection();
        }

        return new NormalizedRequest(method: self::METHOD, uri: $request->path(), original: $request);
    }
}
