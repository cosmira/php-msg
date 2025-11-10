<?php

declare(strict_types=1);

namespace MsgViewer\Tests\IO;

use Brick\Math\BigInteger;
use MsgViewer\IO\BinaryBuffer;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;

final class BinaryBufferTest extends TestCase
{
    public function testScalarReads(): void
    {
        $data = pack('CvvVV', 0x7F, 0x1234, 0xFFFF, 0x89ABCDEF, 0x01234567);
        $buffer = new BinaryBuffer($data);

        self::assertSame(0x7F, $buffer->getUint8(0));
        self::assertSame(0x1234, $buffer->getUint16(1));
        self::assertSame(0xFFFF, $buffer->getUint16(3));
        self::assertSame(0x89ABCDEF, $buffer->getUint32(5));
        self::assertSame(0x01234567, $buffer->getUint32(9));
        self::assertSame(strlen($data), $buffer->length());
    }

    public function testGetBigUint64(): void
    {
        $data = str_repeat("\0", 4).pack('VV', 0x89ABCDEF, 0x01234567);
        $buffer = new BinaryBuffer($data);

        $value = $buffer->getBigUint64(4);
        self::assertInstanceOf(BigInteger::class, $value);
        self::assertSame('81985529216486895', $value->toBase(10));
    }

    public function testSliceAndCopy(): void
    {
        $data = 'HelloBinaryBuffer';
        $buffer = new BinaryBuffer($data);

        self::assertSame('Binary', $buffer->slice(5, 6));

        $target = str_repeat("\0", 6);
        $buffer->copyInto(5, 6, $target);
        self::assertSame('Binary', $target);
    }

    public function testOutOfRange(): void
    {
        $buffer = new BinaryBuffer('abc');

        $this->expectException(OutOfBoundsException::class);
        $buffer->getUint8(5);
    }
}
