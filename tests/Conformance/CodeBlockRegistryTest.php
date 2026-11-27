<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Conformance;

use KaririCode\Exception\ErrorCodeTable;
use KaririCode\WebSocket\Exception\WsCode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * WsCode against the ecosystem registry of record, CODE-BLOCKS.md of
 * kariricode/exception, read from the installed package (ADR-003).
 */
#[CoversNothing]
final class CodeBlockRegistryTest extends TestCase
{
    private const string REGISTRY = __DIR__ . '/../../vendor/kariricode/exception/CODE-BLOCKS.md';

    #[Test]
    public function wsCodeIsAValidCodeTable(): void
    {
        $table = new ErrorCodeTable(WsCode::class);

        self::assertSame('WS', $table->prefix);
        self::assertSame([1800, 1899], [$table->block->first, $table->block->last]);
    }

    #[Test]
    public function theRegistryGivesThisPackageItsBlockAndPrefix(): void
    {
        $rows = array_values(array_filter(
            file(self::REGISTRY, FILE_IGNORE_NEW_LINES) ?: [],
            static fn(string $line): bool => str_contains($line, '| kariricode/websocket |'),
        ));

        self::assertCount(1, $rows, 'one block for kariricode/websocket');
        self::assertMatchesRegularExpression('/\A\| 1800–1899 \| WS \| kariricode\/websocket \| (released|claimed) \|/u', $rows[0]);
    }

    #[Test]
    public function codesFollowTheDocumentedLayout(): void
    {
        $ranges = [
            'frames and close payloads' => [1800, 1819],
            'opening handshake' => [1820, 1839],
            'messages the application sends' => [1840, 1849],
            'connections and the server socket' => [1850, 1859],
            'configuration' => [1860, 1869],
            'router normalizer' => [1870, 1879],
        ];

        foreach (WsCode::cases() as $case) {
            $inRange = array_filter($ranges, static fn(array $range): bool => $case->value >= $range[0] && $case->value <= $range[1]);
            self::assertCount(1, $inRange, $case->name . ' sits in a documented range');
        }
    }
}
