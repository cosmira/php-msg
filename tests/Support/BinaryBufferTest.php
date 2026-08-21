<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Support;

use Cosmira\OutlookMessage\Support\BinaryBuffer;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;

final class BinaryBufferTest extends TestCase
{
    public function testFileBackedBufferReadsRangesWithoutChangingItsContract(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'outlook-msg-buffer-');
        $this->assertIsString($path);

        try {
            file_put_contents($path, "\x01\x02\x03\x04");
            $buffer = BinaryBuffer::fromPath($path);

            $this->assertSame(4, $buffer->length());
            $this->assertSame("\x02\x03", $buffer->slice(1, 2));
            $this->assertSame(0x04030201, $buffer->getUint32(0));
            $this->assertSame("\x01\x02\x03\x04", $buffer->data());
        } finally {
            @unlink($path);
        }
    }

    public function testScalarReads(): void
    {
        $data = pack('CvvVV', 0x7F, 0x1234, 0xFFFF, 0x89ABCDEF, 0x01234567);
        $buffer = new BinaryBuffer($data);

        $this->assertSame(0x7F, $buffer->getUint8(0));
        $this->assertSame(0x1234, $buffer->getUint16(1));
        $this->assertSame(0xFFFF, $buffer->getUint16(3));
        $this->assertSame(0x89ABCDEF, $buffer->getUint32(5));
        $this->assertSame(0x01234567, $buffer->getUint32(9));
        $this->assertSame(strlen($data), $buffer->length());
    }

    public function testGetBigUint64(): void
    {
        $data = str_repeat("\0", 4).pack('VV', 0x89ABCDEF, 0x01234567);
        $buffer = new BinaryBuffer($data);

        $value = $buffer->getBigUint64(4);
        $this->assertSame('81985529216486895', $value->toBase(10));
    }

    public function testSliceAndCopy(): void
    {
        $data = 'HelloBinaryBuffer';
        $buffer = new BinaryBuffer($data);

        $this->assertSame('Binary', $buffer->slice(5, 6));

        $target = str_repeat("\0", 6);
        $buffer->copyInto(5, 6, $target);
        $this->assertSame('Binary', $target);
    }

    public function testOutOfRange(): void
    {
        $buffer = new BinaryBuffer('abc');

        $this->expectException(OutOfBoundsException::class);
        $buffer->getUint8(5);
    }

    public function testDataReturnsOriginalString(): void
    {
        $data = 'Hello Binary Buffer';
        $buffer = new BinaryBuffer($data);

        $this->assertSame($data, $buffer->data());
    }

    public function testGetInt32ReadsSignedValue(): void
    {
        // -1 as little-endian int32 = 0xFF 0xFF 0xFF 0xFF
        $data = pack('l', -1);
        $buffer = new BinaryBuffer($data);

        $this->assertSame(-1, $buffer->getInt32(0));
    }

    public function testGetInt32ReadsNegativeValue(): void
    {
        $data = pack('l', -123456);
        $buffer = new BinaryBuffer($data);

        $this->assertSame(-123456, $buffer->getInt32(0));
    }
}
