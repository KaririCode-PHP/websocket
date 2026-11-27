<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Tests\Unit\Server;

use KaririCode\WebSocket\Server\Silently;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Silently::class)]
final class SilentlyTest extends TestCase
{
    #[Test]
    public function aWarningIsSwallowedAndTheResultReturned(): void
    {
        error_clear_last();

        $result = Silently::run(static fn(): mixed => fopen('/no/such/directory/file', 'r'));

        self::assertFalse($result);
        self::assertNull(error_get_last(), 'the warning never reached PHP');
    }

    #[Test]
    public function thePreviousErrorHandlerComesBackEvenWhenTheOperationThrows(): void
    {
        $previous = static fn(): bool => false;
        set_error_handler($previous);

        try {
            $thrown = '';

            try {
                Silently::run(static function (): mixed {
                    throw new \LogicException('inside');
                });
            } catch (\LogicException $e) {
                $thrown = $e->getMessage();
            }

            self::assertSame('inside', $thrown);
            self::assertSame($previous, set_error_handler(null));
        } finally {
            restore_error_handler();
            restore_error_handler();
        }
    }
}
