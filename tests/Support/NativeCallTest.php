<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Support;

use Cosmira\OutlookMessage\Support\NativeCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(NativeCall::class)]
final class NativeCallTest extends TestCase
{
    public function testSuccessfulCallsRetainTheirResult(): void
    {
        $this->assertSame('value', NativeCall::attempt(static fn () => 'value'));
    }

    public function testWarningsBecomeFailedResultsAndRestoreThePreviousHandler(): void
    {
        $handler = static fn (): bool => true;
        set_error_handler($handler);

        try {
            $this->assertFalse(NativeCall::attempt(static fn () => fopen(__DIR__.'/missing-native-call', 'rb')));
            $previous = set_error_handler(static fn (): bool => true);
            restore_error_handler();
            $this->assertSame($handler, $previous);
        } finally {
            restore_error_handler();
        }
    }

    public function testExceptionsPropagateAndRestoreThePreviousHandler(): void
    {
        $handler = static fn (): bool => true;
        $failure = new RuntimeException('native operation failed');
        set_error_handler($handler);

        try {
            try {
                NativeCall::attempt(static fn () => throw $failure);
                $this->fail('Expected the original exception.');
            } catch (RuntimeException $caught) {
                $this->assertSame($failure, $caught);
            }

            $previous = set_error_handler(static fn (): bool => true);
            restore_error_handler();
            $this->assertSame($handler, $previous);
        } finally {
            restore_error_handler();
        }
    }
}
