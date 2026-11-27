<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Integration;

use KaririCode\Http\Security\TlsConfiguration;
use KaririCode\Router\Core\RouteRegistry;
use KaririCode\WebSocket\Connection\WebSocketConnection;
use KaririCode\WebSocket\Exception\UnsupportedRequestException;
use KaririCode\WebSocket\Exception\WsCode;
use KaririCode\WebSocket\Handshake\HandshakeRequest;
use KaririCode\WebSocket\Integration\TlsContext;
use KaririCode\WebSocket\Integration\WebSocketNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketNormalizer::class)]
#[CoversClass(TlsContext::class)]
final class IntegrationTest extends TestCase
{
    #[Test]
    public function aConnectionIsRoutedByItsPath(): void
    {
        $connection = new WebSocketConnection('c1', '127.0.0.1:1', new HandshakeRequest('/chat/42?token=t', []));
        $routes = (new RouteRegistry())->route([WebSocketNormalizer::METHOD], '/chat/{room}', 'ChatRoom');
        $normalizer = new WebSocketNormalizer();

        $request = $normalizer->normalize($connection);
        $match = $routes->match($request->method, $request->uri);

        self::assertTrue($normalizer->canNormalize($connection));
        self::assertFalse($normalizer->canNormalize(new \stdClass()));
        self::assertSame('WEBSOCKET', $request->method);
        self::assertSame('/chat/42', $request->uri);
        self::assertSame($connection, $request->original);
        self::assertSame('ChatRoom', $match->handler());
        self::assertSame('42', $match->parameters->get('room'));
    }

    #[Test]
    public function normalizingSomethingElseIsAWiringError(): void
    {
        try {
            (new WebSocketNormalizer())->normalize(new \stdClass());
            self::fail('a non-connection was normalised');
        } catch (UnsupportedRequestException $e) {
            self::assertSame(WsCode::NotAConnection, $e->errorCode);
        }
    }

    #[Test]
    public function theTlsContextCarriesTheConfigurationWithoutCompression(): void
    {
        $context = TlsContext::create(TlsConfiguration::mutual('/etc/tls/server.pem', '/etc/tls/server.key', '/etc/tls/ca.pem'));
        $options = stream_context_get_options($context);
        $ssl = $options['ssl'] ?? null;
        $socket = $options['socket'] ?? null;

        self::assertIsArray($ssl);
        self::assertIsArray($socket);
        self::assertTrue($ssl['disable_compression']);
        self::assertSame('/etc/tls/server.pem', $ssl['local_cert']);
        self::assertTrue($ssl['verify_peer']);
        self::assertTrue($ssl['capture_peer_cert']);
        self::assertSame(128, $socket['backlog']);
    }

    #[Test]
    public function aStreamWithoutCapturedCertificateHasNone(): void
    {
        $stream = fopen('php://memory', 'r');
        self::assertIsResource($stream);

        self::assertNull(TlsContext::clientCertificate($stream));

        stream_context_set_option($stream, 'ssl', 'peer_certificate', 'not a certificate');
        self::assertNull(TlsContext::clientCertificate($stream));
    }

    #[Test]
    public function aCapturedCertificateIsRead(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $request = openssl_csr_new(['commonName' => 'client-7'], $key);
        self::assertInstanceOf(\OpenSSLCertificateSigningRequest::class, $request);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        $certificate = openssl_csr_sign($request, null, $key, 1);
        self::assertNotFalse($certificate);
        $stream = fopen('php://memory', 'r');
        self::assertIsResource($stream);
        stream_context_set_option($stream, 'ssl', 'peer_certificate', $certificate);

        while (openssl_error_string() !== false) {
            // OpenSSL queues configuration notes the test does not need.
        }

        self::assertSame('client-7', TlsContext::clientCertificate($stream)?->commonName());
    }
}
