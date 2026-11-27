<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Server;

use KaririCode\Http\Security\PeerVerifyMode;
use KaririCode\Http\Security\TlsConfiguration;
use KaririCode\WebSocket\Server\TlsContext;
use PHPUnit\Framework\TestCase;

final class TlsContextTest extends TestCase
{
    // ── create() ─────────────────────────────────────────────────────────

    public function testCreateReturnsStreamContextResource(): void
    {
        $config = TlsConfiguration::serverOnly('/cert.pem', '/key.pem');
        $context = TlsContext::create($config);

        self::assertIsResource($context);
        self::assertSame('stream-context', get_resource_type($context));
    }

    public function testCreateIncludesSslOptions(): void
    {
        $config = TlsConfiguration::mutual('/cert.pem', '/key.pem', '/ca.pem');
        $context = TlsContext::create($config);

        $params = stream_context_get_options($context);

        self::assertArrayHasKey('ssl', $params);
        self::assertSame('/cert.pem', $params['ssl']['local_cert']);
        self::assertSame('/key.pem', $params['ssl']['local_pk']);
        self::assertSame('/ca.pem', $params['ssl']['cafile']);
        self::assertTrue($params['ssl']['verify_peer']);
        self::assertTrue($params['ssl']['capture_peer_cert']);
    }

    public function testCreateDisablesCompression(): void
    {
        $config = TlsConfiguration::serverOnly('/cert.pem', '/key.pem');
        $context = TlsContext::create($config);

        $params = stream_context_get_options($context);

        self::assertTrue($params['ssl']['disable_compression']);
    }

    public function testCreateIncludesSocketBacklog(): void
    {
        $config = new TlsConfiguration();
        $context = TlsContext::create($config);

        $params = stream_context_get_options($context);

        self::assertSame(128, $params['socket']['backlog']);
    }

    public function testCreateWithNoMtlsDoesNotCaptureClient(): void
    {
        $config = TlsConfiguration::serverOnly('/cert.pem', '/key.pem');
        $context = TlsContext::create($config);

        $params = stream_context_get_options($context);

        self::assertArrayNotHasKey('capture_peer_cert', $params['ssl']);
    }

    public function testCreateWithOptionalModeVerifiesPeer(): void
    {
        $config = TlsConfiguration::optionalClient('/cert.pem', '/key.pem', '/ca.pem');
        $context = TlsContext::create($config);

        $params = stream_context_get_options($context);

        self::assertTrue($params['ssl']['verify_peer']);
    }

    // ── transportScheme() ────────────────────────────────────────────────

    public function testTransportSchemeReturnsTlsWhenCertConfigured(): void
    {
        $config = TlsConfiguration::serverOnly('/cert.pem', '/key.pem');

        self::assertSame('tls', TlsContext::transportScheme($config));
    }

    public function testTransportSchemeReturnsTcpWhenNoCert(): void
    {
        $config = new TlsConfiguration();

        self::assertSame('tcp', TlsContext::transportScheme($config));
    }

    // ── extractClientCertificate() ───────────────────────────────────────

    public function testExtractClientCertificateReturnsNullForNonTlsStream(): void
    {
        // Create a plain memory stream (no TLS, no peer cert)
        $stream = fopen('php://memory', 'r+');

        $cert = TlsContext::extractClientCertificate($stream);

        self::assertNull($cert);

        fclose($stream);
    }
}
