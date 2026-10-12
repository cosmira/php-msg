<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Support;

use RuntimeException;

/** @internal */
final class AtomicFile
{
    /**
     * Write beside the target and publish only a flushed, complete file.
     *
     * @param callable(resource): void $writer
     */
    public static function write(string $path, callable $writer): void
    {
        $directory = dirname($path);
        $existingMode = is_file($path) ? fileperms($path) : false;
        $temporary = NativeCall::attempt(static fn () => tempnam($directory, '.outlook-msg-'));
        if ($temporary === false) {
            throw new RuntimeException(sprintf(
                'Unable to create a temporary message beside "%s".',
                $path,
            ));
        }

        $destination = NativeCall::attempt(static fn () => fopen($temporary, 'wb'));

        try {
            throw_if(
                $destination === false,
                RuntimeException::class,
                sprintf('Unable to open message destination "%s".', $temporary),
            );
            $writer($destination);
            self::flushAndClose($destination, $temporary);
            $destination = null;
            if ($existingMode !== false) {
                throw_unless(
                    NativeCall::attempt(static fn () => chmod($temporary, $existingMode & 0777)),
                    RuntimeException::class,
                    sprintf('Unable to preserve permissions for "%s".', $path),
                );
            }

            throw_unless(
                NativeCall::attempt(static fn () => rename($temporary, $path)),
                RuntimeException::class,
                sprintf('Unable to write message to "%s".', $path),
            );
        } finally {
            if (is_resource($destination)) {
                fclose($destination);
            }

            if (is_file($temporary)) {
                // Cleanup is best effort: a failure must not replace the original write exception.
                NativeCall::attempt(static fn () => unlink($temporary));
            }
        }
    }

    /**
     * Flush the complete temporary file before it can replace the target.
     *
     * @param resource $destination
     */
    private static function flushAndClose(mixed $destination, string $temporary): void
    {
        throw_unless(
            fflush($destination),
            RuntimeException::class,
            sprintf('Unable to flush message destination "%s".', $temporary),
        );
        if (function_exists('fsync')) {
            throw_unless(
                fsync($destination),
                RuntimeException::class,
                sprintf('Unable to synchronize message destination "%s".', $temporary),
            );
        }

        throw_unless(
            fclose($destination),
            RuntimeException::class,
            sprintf('Unable to close message destination "%s".', $temporary),
        );
    }
}
