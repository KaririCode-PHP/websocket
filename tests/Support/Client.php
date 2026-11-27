<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Support;

/**
 * What a WebSocket client sends: the opening handshake and masked frames,
 * with knobs to break each rule a test needs broken.
 */
final class Client
{
    public const string KEY = 'dGhlIHNhbXBsZSBub25jZQ==';

    /** RFC 6455 §1.3: the accept value for KEY. */
    public const string ACCEPT = 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=';

    public const string MASK = "\x37\xfa\x21\x3d";

    /**
     * @param array<string, string|null> $headers replaces the default fields; null removes one
     */
    public static function handshake(array $headers = [], string $requestLine = 'GET /chat?room=7 HTTP/1.1'): string
    {
        $fields = array_filter($headers + [
            'Host' => 'server.example.com',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => self::KEY,
            'Sec-WebSocket-Version' => '13',
            'Origin' => 'https://app.example.com',
        ], static fn(?string $value): bool => $value !== null);
        $head = $requestLine . "\r\n";

        foreach ($fields as $name => $value) {
            $head .= $name . ': ' . $value . "\r\n";
        }

        return $head . "\r\n";
    }

    /** A masked frame; $length overrides the 7-bit length field to write non-minimal encodings. */
    public static function frame(int $opcode, string $payload = '', bool $fin = true, int $rsv = 0, bool $masked = true, ?int $length = null): string
    {
        $size = strlen($payload);
        $head = chr(($fin ? 0x80 : 0) | ($rsv << 4) | $opcode);
        $mask = $masked ? 0x80 : 0;
        $short = $length ?? ($size <= 125 ? $size : ($size <= 0xFFFF ? 126 : 127));
        $extended = match ($short) {
            126 => pack('n', $size),
            127 => pack('J', $size),
            default => '',
        };

        return $head . chr($mask | $short) . $extended . ($masked ? self::MASK . self::mask($payload) : $payload);
    }

    public static function text(string $payload, bool $fin = true): string
    {
        return self::frame(0x1, $payload, $fin);
    }

    public static function close(?int $code = 1000, string $reason = ''): string
    {
        return self::frame(0x8, $code === null ? '' : pack('n', $code) . $reason);
    }

    public static function mask(string $payload): string
    {
        $masked = '';

        foreach (str_split($payload) as $index => $byte) {
            $masked .= $byte ^ self::MASK[$index % 4];
        }

        return $payload === '' ? '' : $masked;
    }
}
