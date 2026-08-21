<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Support;

use Closure;
use RuntimeException;

final class BinarySource
{
    private const CHUNK_SIZE = 1024 * 1024;

    /**
     * The calculated digests keyed by hashing algorithm.
     *
     * @var array<string, string>
     */
    private array $hashes = [];

    /**
     * Create a binary source with a known byte length and streaming reader.
     */
    private function __construct(
        /**
         * The exact byte length or a resolver for a lazily inspected source.
         *
         * @var int|Closure(): int
         */
        private readonly int|Closure $size,
        /**
         * The callback that copies the source into a writable stream.
         *
         * @var Closure(resource): void
         */
        private readonly Closure $writer,
        /**
         * The stable random-access buffer retained by file-backed message sources.
         */
        private readonly ?BinaryBuffer $buffer = null,
        /**
         * The optional callback that hashes the source without an intermediate spool.
         *
         * @var (Closure(string): string)|null
         */
        private readonly ?Closure $hasher = null,
    ) {}

    /**
     * Create a source backed by an in-memory binary string.
     */
    public static function fromString(string $contents): self
    {
        return new self(strlen($contents), static function ($destination) use ($contents): void {
            throw_unless(is_resource($destination), RuntimeException::class, 'Binary source destination must be a writable stream.');
            self::writeAll($destination, $contents);
        });
    }

    /**
     * Create a source backed by a file that is opened only while being read.
     */
    public static function fromPath(string $path): self
    {
        return new self(static function () use ($path): int {
            $size = @filesize($path);
            throw_if($size === false, RuntimeException::class, sprintf('Unable to read attachment from "%s".', $path));

            return $size;
        }, static function ($destination) use ($path): void {
            throw_unless(is_resource($destination), RuntimeException::class, 'Binary source destination must be a writable stream.');
            $source = @fopen($path, 'rb');
            throw_if($source === false, RuntimeException::class, sprintf('Unable to read attachment from "%s".', $path));

            try {
                $copied = stream_copy_to_stream($source, $destination);
                throw_if($copied === false, RuntimeException::class, sprintf('Unable to read attachment from "%s".', $path));
            } finally {
                fclose($source);
            }
        });
    }

    /**
     * Create a source backed by a stable random-access binary buffer.
     */
    public static function fromBuffer(BinaryBuffer $buffer): self
    {
        return new self(
            $buffer->length(),
            static function ($destination) use ($buffer): void {
                throw_unless(is_resource($destination), RuntimeException::class, 'Binary source destination must be a writable stream.');

                $length = $buffer->length();
                for ($offset = 0; $offset < $length; $offset += self::CHUNK_SIZE) {
                    self::writeAll($destination, $buffer->slice($offset, min(self::CHUNK_SIZE, $length - $offset)));
                }
            },
            $buffer,
            static function (string $algorithm) use ($buffer): string {
                $context = hash_init($algorithm);
                $length = $buffer->length();
                for ($offset = 0; $offset < $length; $offset += self::CHUNK_SIZE) {
                    hash_update($context, $buffer->slice($offset, min(self::CHUNK_SIZE, $length - $offset)));
                }

                return hash_final($context);
            },
        );
    }

    /**
     * Create a source from a callback that writes exactly the declared number of bytes.
     *
     * @param Closure(resource): void        $writer
     * @param (Closure(string): string)|null $hasher
     */
    public static function fromWriter(int $size, Closure $writer, ?Closure $hasher = null): self
    {
        throw_if($size < 0, RuntimeException::class, 'Binary source size cannot be negative.');

        return new self($size, $writer, hasher: $hasher);
    }

    /**
     * Get the exact number of bytes exposed by the source.
     */
    public function size(): int
    {
        $size = $this->size instanceof Closure ? ($this->size)() : $this->size;
        throw_if($size < 0, RuntimeException::class, 'Binary source size cannot be negative.');

        return $size;
    }

    /**
     * Copy the complete source into the given writable stream.
     *
     * @param resource $destination
     */
    public function copyTo($destination): void
    {
        throw_unless(is_resource($destination), RuntimeException::class, 'Binary source destination must be a writable stream.');

        $start = ftell($destination);
        $expected = $this->size();
        ($this->writer)($destination);

        if ($start === false) {
            return;
        }

        $end = ftell($destination);
        throw_if($end === false || $end - $start !== $expected, RuntimeException::class, 'Binary source did not write its declared byte length.');
    }

    /**
     * Materialize and return the complete source contents.
     */
    public function contents(): string
    {
        $stream = fopen('php://temp', 'w+b');
        throw_if($stream === false, RuntimeException::class, 'Unable to create a temporary binary stream.');

        try {
            $this->copyTo($stream);
            rewind($stream);
            $contents = stream_get_contents($stream);
            throw_if($contents === false, RuntimeException::class, 'Unable to read the temporary binary stream.');

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Calculate a hash without materializing the complete source contents.
     */
    public function hash(string $algorithm = 'sha256'): string
    {
        if (isset($this->hashes[$algorithm])) {
            return $this->hashes[$algorithm];
        }

        if ($this->hasher instanceof Closure) {
            return $this->hashes[$algorithm] = ($this->hasher)($algorithm);
        }

        $context = hash_init($algorithm);
        $stream = fopen('php://temp/maxmemory:'.self::CHUNK_SIZE, 'w+b');
        throw_if($stream === false, RuntimeException::class, 'Unable to create a hashing stream.');

        try {
            hash_update_stream($context, $this->asReadableStream($stream));

            return $this->hashes[$algorithm] = hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Return the retained random-access buffer when the source provides one.
     *
     * @internal
     */
    public function buffer(): ?BinaryBuffer
    {
        return $this->buffer;
    }

    /**
     * Populate and rewind the given temporary stream for sequential reading.
     *
     * @param resource $stream
     *
     * @return resource
     */
    private function asReadableStream($stream)
    {
        $this->copyTo($stream);
        rewind($stream);

        return $stream;
    }

    /**
     * Write all bytes to the given destination, including after partial writes.
     *
     * @param resource $destination
     */
    private static function writeAll($destination, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($destination, substr($contents, $offset, self::CHUNK_SIZE));
            throw_if($written === false || $written === 0, RuntimeException::class, 'Unable to write binary source contents.');
            $offset += $written;
        }
    }
}
