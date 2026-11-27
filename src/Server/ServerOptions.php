<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Server;

use KaririCode\WebSocket\Exception\InvalidConfigurationException;

/**
 * The limits and timeouts of a WebSocketServer: the largest frame and
 * message a client may send, how much unsent output a connection may hold
 * before it is dropped (a client that stops reading), how long one loop
 * round waits for sockets, how long the server waits for the client's
 * Close after sending its own, and how many bytes it reads at a time.
 *
 * ARFA 1.3: P1 (immutable). Spec V4.3.3: final readonly Value Object,
 * validated on construction.
 *
 * Preconditions: sizes ≥ 1 (InvalidConfigurationException 1860); timeouts
 * ≥ 0 seconds (1861).
 *
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $server = new WebSocketServer($handler, options: new ServerOptions(maxMessageSize: 65_536, closeTimeout: 2.0));
 *
 * References: RFC 6455 §7.1.1 (closing the TCP connection), §10.4
 * (implementation-specific limits); ADR-004.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-10.4
 */
final readonly class ServerOptions
{
    /**
     * @param positive-int $readChunk bytes read from a socket at a time
     */
    public function __construct(
        public int $maxPayloadSize = 1_048_576,
        public int $maxMessageSize = 10_485_760,
        public int $maxBufferedOutput = 16_777_216,
        public float $selectTimeout = 0.2,
        public float $closeTimeout = 5.0,
        public int $readChunk = 65_536,
    ) {
        foreach (['maxPayloadSize' => $maxPayloadSize, 'maxMessageSize' => $maxMessageSize, 'maxBufferedOutput' => $maxBufferedOutput, 'readChunk' => $readChunk] as $name => $size) {
            if ($size < 1) {
                throw InvalidConfigurationException::nonPositiveLimit($name);
            }
        }

        foreach (['selectTimeout' => $selectTimeout, 'closeTimeout' => $closeTimeout] as $name => $seconds) {
            if ($seconds < 0) {
                throw InvalidConfigurationException::negativeTimeout($name);
            }
        }
    }
}
