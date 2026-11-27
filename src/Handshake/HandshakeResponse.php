<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Handshake;

/**
 * The server's answer to an accepted opening handshake: the bytes of the
 * 101 Switching Protocols response and the subprotocol it selected, if
 * any, which the connection then reports to the application.
 *
 * ARFA 1.3: P1 (immutable). Spec V4.3.3: final readonly Value Object.
 *
 * Preconditions: built by HandshakeNegotiator::negotiate().
 *
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $response = $negotiator->negotiate($request);
 *     fwrite($socket, $response->head);
 *     $response->subprotocol;   // "graphql-transport-ws" or null
 *
 * References: RFC 6455 §4.2.2 (server handshake), §1.9 (subprotocols);
 * ADR-005.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-4.2.2
 */
final readonly class HandshakeResponse
{
    public function __construct(
        public string $head,
        public ?string $subprotocol = null,
    ) {}
}
