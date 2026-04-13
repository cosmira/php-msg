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

        self::assertSame(0x003E, $header->minorVersion);
        self::assertSame(0x0003, $header->majorVersion);
        self::assertSame(0xFFFE, $header->byteOrder);
        self::assertSame(512, $header->sectorSize);
        self::assertSame(64, $header->miniSectorSize);
        self::assertSame([2], $header->difat);
        self::assertSame(0xFFFFFFFE, $header->firstDifatSectorLocation);
    }

    public function testInvalidSignatureThrows(): void
    {
        $binary = str_repeat("\x00", 512);
        $buffer = new BinaryBuffer($binary);

        $this->expectException(RuntimeException::class);
        Header::parse($buffer);
    }
}
