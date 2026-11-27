<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Integration;

use KaririCode\Router\Contract\RequestNormalizer;
use KaririCode\Router\Core\NormalizedRequest;
use KaririCode\WebSocket\Contract\Connection;

/**
 * Bridges WebSocket connections into Router V4.1 string-based matching.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): implements the
 * {@see RequestNormalizer} contract from kariricode/router. This class
 * lives in kariricode/websocket (not in Router) so the Router package
 * has zero knowledge of WebSocket types.
 *
 * Spec V4.0: final class, zero state, pure transformation.
 *
 * Mapping convention (RFC 6455 §4.1 — client handshake request URI):
 *   method  → "WEBSOCKET" (pseudo-method for route registration)
 *   uri     → connection path (e.g. "/ws/chat/room-42")
 *   original → the {@see Connection} object for handler-level access
 *
 * Dependency direction: WebSocket → Router (never reversed).
 * Adding a new protocol requires a new normalizer in that protocol's
 * package — zero changes here or in the Router.
 *
 * @see \KaririCode\Router\Contract\RequestNormalizer
 * @see \KaririCode\Router\Core\NormalizedRequest
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6455 WebSocket Protocol
 */
final class WebSocketNormalizer implements RequestNormalizer
{
    public function canNormalize(object $request): bool
    {
        return $request instanceof Connection;
    }

    public function normalize(object $request): NormalizedRequest
    {
        assert($request instanceof Connection);

        return new NormalizedRequest(
            method: 'WEBSOCKET',
            uri: $request->path() ?: '/',
            original: $request,
        );
    }
}

