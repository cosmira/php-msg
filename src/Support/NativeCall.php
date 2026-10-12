<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Support;

use ErrorException;

/** @internal */
final class NativeCall
{
    /**
     * Translate a native warning into a failed result for the caller to handle.
     *
     * The previous warning handler is always restored, including when the call throws.
     *
     * @template Result
     *
     * @param callable(): Result $operation
     *
     * @return Result|false
     */
    public static function attempt(callable $operation): mixed
    {
        set_error_handler(
            static fn (int $severity, string $message) => throw new ErrorException(
                $message,
                0,
                $severity,
            ),
            E_WARNING,
        );

        try {
            return $operation();
        } catch (ErrorException) {
            return false;
        } finally {
            restore_error_handler();
        }
    }
}
