<?php

declare(strict_types=1);

namespace MsgViewer\IO;

use Brick\Math\BigInteger;
use OutOfBoundsException;

/**
 * Immutable wrapper around a binary string that exposes DataView-like helpers.
 */
final class BinaryBuffer
{
    private string $data;
    private int $length;

    public function __construct(string $data)
    {
        $this->data = $data;
        $this->length = strlen($data);
    }

    public function length(): int
    {
        return $this->length;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function slice(int $offset, int $length): string
    {
        $this->assertRange($offset, $length);

        return substr($this->data, $offset, $length);
    }

    public function getUint8(int $offset): int
    {
        $this->assertRange($offset, 1);

        return ord($this->data[$offset]);
    }

    public function getUint16(int $offset): int
    {
        $bytes = $this->slice($offset, 2);
        $values = unpack('vvalue', $bytes);

        return (int) $values['value'];
    }

    public function getUint32(int $offset): int
    {
        $bytes = $this->slice($offset, 4);
        $values = unpack('Vvalue', $bytes);

        return (int) $values['value'];
    }

    public function getInt32(int $offset): int
    {
        $bytes = $this->slice($offset, 4);
        $values = unpack('lvalue', $bytes);

        return (int) $values['value'];
    }

    public function getBigUint64(int $offset): BigInteger
    {
        $bytes = $this->slice($offset, 8);
        $parts = unpack('Vlow/Vhigh', $bytes);

        return BigInteger::of($parts['low'])
            ->plus(BigInteger::of($parts['high'])->shiftedLeft(32));
    }

    public function copyInto(int $offset, int $length, string &$target, int $targetOffset = 0): void
    {
        $this->assertRange($offset, $length);
        $target = substr_replace($target, substr($this->data, $offset, $length), $targetOffset, $length);
    }

    private function assertRange(int $offset, int $length): void
    {
        if ($offset < 0 || $length < 0 || ($offset + $length) > $this->length) {
            throw new OutOfBoundsException(sprintf('Requested range [%d, %d) outside buffer length %d.', $offset, $offset + $length, $this->length));
        }
    }
}
