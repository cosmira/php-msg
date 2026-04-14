<?php

declare(strict_types=1);

namespace MsgViewer\Tests\CompoundFile;

use MsgViewer\CompoundFile\Header;
use MsgViewer\Support\BinaryBuffer;
use MsgViewer\Tests\Support\CompoundFileBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HeaderTest extends TestCase
{
    public function testParseValidHeader(): void
    {
        $binary = CompoundFileBuilder::createHeaderBinary();
        $buffer = new BinaryBuffer($binary);

        $header = Header::parse($buffer);

        $this->assertSame(0x003E, $header->minorVersion);
        $this->assertSame(0x0003, $header->majorVersion);
        $this->assertSame(0xFFFE, $header->byteOrder);
        $this->assertSame(512, $header->sectorSize);
        $this->assertSame(64, $header->miniSectorSize);
        $this->assertSame([2], $header->difat);
        $this->assertSame(0xFFFFFFFE, $header->firstDifatSectorLocation);
    }

    public function testInvalidSignatureThrows(): void
    {
        $binary = str_repeat("\x00", 512);
        $buffer = new BinaryBuffer($binary);

        $this->expectException(RuntimeException::class);
        Header::parse($buffer);
    }

    public function testInvalidByteOrderThrows(): void
    {
        $binary = CompoundFileBuilder::createHeaderBinary(byteOrder: 0xFEFF);
        $buffer = new BinaryBuffer($binary);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/byte order/i');
        Header::parse($buffer);
    }

    public function testInvalidMiniStreamCutoffSizeThrows(): void
    {
        $binary = CompoundFileBuilder::createHeaderBinary(miniStreamCutOffSize: 512);
        $buffer = new BinaryBuffer($binary);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/mini stream cutoff/i');
        Header::parse($buffer);
    }

    public function testInvalidSectorShiftForVersionThrows(): void
    {
        $binary = CompoundFileBuilder::createHeaderBinary(sectorShift: 12); // v3 must be 9
        $buffer = new BinaryBuffer($binary);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sector size shift/i');
        Header::parse($buffer);
    }

    public function testInvalidMiniSectorShiftThrows(): void
    {
        $binary = CompoundFileBuilder::createHeaderBinary(miniSectorShift: 7);
        $buffer = new BinaryBuffer($binary);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/mini sector size shift/i');
        Header::parse($buffer);
    }
}
