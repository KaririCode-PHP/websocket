<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Server;

use KaririCode\Http\Security\ClientCertificate;
use KaririCode\Http\Security\TlsConfiguration;

/**
 * Builds PHP stream context for TLS-enabled WebSocket servers.
 *
 * When the WebSocket server terminates TLS directly (no reverse
 * proxy), this class converts a {@see TlsConfiguration} into
 * the resource returned by stream_context_create(), ready for
 * stream_socket_server() with an ssl:// or tls:// transport.
 *
 * For mTLS, it also provides extraction of the peer certificate
 * from the established connection via {@see extractClientCertificate()},
 * which reads the captured certificate from the stream context and
 * returns an immutable {@see ClientCertificate} value object.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): consumes the same
 * TlsConfiguration used by HTTP and gRPC layers.
 *
 * Spec V4.0: final class, stateless.
 *
 * @see https://www.php.net/manual/en/context.ssl.php
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-11.1.2
 */
final class TlsContext
{
    /**
     * Creates a stream context for stream_socket_server().
     *
     * Usage:
     *   $context = TlsContext::create($tlsConfig);
     *   $server = stream_socket_server('tls://0.0.0.0:8443', ..., $context);
     *
     * @return resource Stream context resource
     */
    public static function create(TlsConfiguration $config): mixed
    {
        $sslOptions = $config->toStreamContextOptions();

        // WebSocket-specific: always set SNI and peer name
        $sslOptions['disable_compression'] = true;

        return stream_context_create([
            'ssl' => $sslOptions,
            'socket' => ['backlog' => 128],
        ]);
    }

    /**
     * Extracts the client certificate from an established TLS stream.
     *
     * Requires capture_peer_cert = true in the stream context (set
     * automatically by TlsConfiguration when PeerVerifyMode::Require).
     *
     * @param resource $stream The connected client socket
     * @return ClientCertificate|null Null if no peer cert captured
     */
    public static function extractClientCertificate(mixed $stream): ?ClientCertificate
    {
        $params = stream_context_get_params($stream);
        $peerCert = $params['options']['ssl']['peer_certificate'] ?? null;

        if (!$peerCert instanceof \OpenSSLCertificate) {
            return null;
        }

        return ClientCertificate::fromOpenSslCertificate($peerCert);
    }

    /**
     * Returns the transport scheme for stream_socket_server().
     *
     * When TLS is configured, returns 'tls://' instead of 'tcp://'.
     */
    public static function transportScheme(TlsConfiguration $config): string
    {
        return $config->hasServerCertificate() ? 'tls' : 'tcp';
    }
}
