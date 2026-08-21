<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\CompoundFile;

use Cosmira\OutlookMessage\CompoundFile\Header;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Tests\Support\CompoundFileBuilder;
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

    public function testParseValidHeaderWithMiniFatValues(): void
    {
        $binary = CompoundFileBuilder::createHeaderBinary();
        $binary = substr_replace($binary, pack('V', 12), 60, 4);
        $binary = substr_replace($binary, pack('V', 3), 64, 4);

        $header = Header::parse(new BinaryBuffer($binary));

        $this->assertSame(12, $header->firstMiniFatSectorLocation);
        $this->assertSame(3, $header->numberOfMiniFatSectors);
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

    public function testUnsupportedMajorVersionThrows(): void
    {
        $binary = substr_replace(CompoundFileBuilder::createHeaderBinary(), pack('v', 2), 26, 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported compound-file major version');

        Header::parse(new BinaryBuffer($binary));
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
