<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Integration;

use KaririCode\Http\Security\ClientCertificate;
use KaririCode\Http\Security\TlsConfiguration;

/**
 * Bridges kariricode/http's TlsConfiguration to PHP stream sockets for a
 * caller that terminates TLS (wss://) in its own loop: create() builds the
 * stream context for stream_socket_server('tls://…'), with TLS compression
 * off (CRIME), and clientCertificate() reads the certificate PHP captured
 * from a client that presented one.
 *
 * ARFA 1.3: P4 (TLS settings shared with kariricode/http). Spec V4.3.3:
 * final, stateless functions.
 *
 * Preconditions: ext-openssl; a TlsConfiguration with a server certificate
 * and key. With mutual TLS, the configuration verifies peers, so PHP
 * captures the peer certificate.
 *
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none; the caller opens the socket.
 *
 * Example:
 *
 *     $server = stream_socket_server('tls://0.0.0.0:8443', $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, TlsContext::create($tls));
 *     $client = stream_socket_accept($server, 5);
 *     $certificate = TlsContext::clientCertificate($client);   // null without one
 *
 * References: RFC 6455 §10.6 (TLS); RFC 8446; PHP manual, SSL context
 * options; kariricode/http, decision record 007 (client-certificate trust).
 *
 * @see https://www.php.net/manual/en/context.ssl.php
 */
final class TlsContext
{
    /**
     * @return resource a stream context
     */
    public static function create(TlsConfiguration $config): mixed
    {
        return stream_context_create(['ssl' => ['disable_compression' => true] + $config->toStreamContextOptions(), 'socket' => ['backlog' => 128]]);
    }

    /**
     * The certificate PHP captured on $stream, or null when the client sent none.
     *
     * @param resource $stream an accepted TLS stream
     */
    public static function clientCertificate(mixed $stream): ?ClientCertificate
    {
        return self::certificate(stream_context_get_options($stream)['ssl'] ?? null);
    }

    private static function certificate(mixed $ssl): ?ClientCertificate
    {
        return is_array($ssl) ? self::fromCaptured($ssl['peer_certificate'] ?? null) : null;
    }

    private static function fromCaptured(mixed $certificate): ?ClientCertificate
    {
        return $certificate instanceof \OpenSSLCertificate ? ClientCertificate::fromOpenSslCertificate($certificate) : null;
    }
}
