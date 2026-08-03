<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Support;

use Brick\Math\BigInteger;
use OutOfBoundsException;

/**
 * Immutable wrapper around a binary string that provides typed read operations
 * similar to a DataView in JavaScript.
 *
 * All methods are little-endian, as required by the Compound File Binary Format (CFBF).
 */
final readonly class BinaryBuffer
{
    /**
     * The cached binary length in bytes.
     */
    private int $length;

    /**
     * Create an immutable buffer for the given binary data.
     */
    public function __construct(
        /**
         * The immutable binary payload.
         */
        private string $data,
    ) {
        $this->length = strlen($this->data);
    }

    /**
     * Get the length of the buffer in bytes.
     */
    public function length(): int
    {
        return $this->length;
    }

    /**
     * Get the complete binary contents of the buffer.
     */
    public function data(): string
    {
        return $this->data;
    }

    /**
     * Returns a substring from the buffer.
     *
     * @throws OutOfBoundsException if range exceeds buffer size.
     */
    public function slice(int $offset, int $length): string
    {
        $this->assertRange($offset, $length);

        return substr($this->data, $offset, $length);
    }

    /**
     * Reads an unsigned 8-bit integer.
     */
    public function getUint8(int $offset): int
    {
        $this->assertRange($offset, 1);

        return ord($this->data[$offset]);
    }

    /**
     * Reads an unsigned 16-bit integer (little-endian).
     */
    public function getUint16(int $offset): int
    {
        /**
         * @var array{value: int} $values
         */
        $values = unpack('vvalue', $this->slice($offset, 2));

        return $values['value'];
    }

    /**
     * Reads an unsigned 32-bit integer (little-endian).
     */
    public function getUint32(int $offset): int
    {
        /**
         * @var array{value: int} $values
         */
        $values = unpack('Vvalue', $this->slice($offset, 4));

        return $values['value'];
    }

    /**
     * Reads a signed 32-bit integer (little-endian).
     */
    public function getInt32(int $offset): int
    {
        /**
         * @var array{value: int} $values
         */
        $values = unpack('lvalue', $this->slice($offset, 4));

        return $values['value'];
    }

    /**
     * Reads an unsigned 64-bit integer as BigInteger (little-endian).
     */
    public function getBigUint64(int $offset): BigInteger
    {
        /**
         * @var array{low: int, high: int} $parts
         */
        $parts = unpack('Vlow/Vhigh', $this->slice($offset, 8));

        return BigInteger::of($parts['low'])
            ->plus(BigInteger::of($parts['high'])->shiftedLeft(32));
    }

    /**
     * Copies a segment of the buffer into a target string.
     *
     * @param int    $offset       Starting offset in source buffer.
     * @param int    $length       Number of bytes to copy.
     * @param string &$target      Destination string (modified in place).
     * @param int    $targetOffset Position to start writing in the target.
     */
    public function copyInto(int $offset, int $length, string &$target, int $targetOffset = 0): void
    {
        $this->assertRange($offset, $length);

        $segment = substr($this->data, $offset, $length);
        $target = substr_replace($target, $segment, $targetOffset, $length);
    }

    /**
     * Ensures that the requested range lies entirely within the buffer.
     *
     * @throws OutOfBoundsException
     */
    private function assertRange(int $offset, int $length): void
    {
        if ($offset >= 0 && $length >= 0 && ($offset + $length) <= $this->length) {
            return;
        }

        throw new OutOfBoundsException(
            sprintf(
                'Requested range [%d, %d) is outside buffer length (%d).',
                $offset,
                $offset + $length,
                $this->length
            )
        );
    }
}
