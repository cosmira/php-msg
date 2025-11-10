<?php

declare(strict_types=1);

namespace MsgViewer\Tests\CompoundFile;

use Brick\Math\BigInteger;
use MsgViewer\CompoundFile\Header;
use MsgViewer\CompoundFile\Util;
use MsgViewer\IO\BinaryBuffer;
use PHPUnit\Framework\TestCase;
use MsgViewer\Tests\Support\CompoundFileBuilder;

final class UtilTest extends TestCase
{
    private Header $header;

    protected function setUp(): void
    {
        $headerBinary = CompoundFileBuilder::createHeaderBinary();
        $this->header = Header::parse(new BinaryBuffer($headerBinary));
    }

    public function testSectorOffset(): void
    {
        self::assertSame(512, Util::sectorOffset(0, $this->header->sectorSize));
        self::assertSame(1024, Util::sectorOffset(1, $this->header->sectorSize));
    }

    public function testStreamSectorOffsetRegular(): void
    {
        $offset = Util::streamSectorOffset(2, $this->header, BigInteger::of(5000), []);
        self::assertSame(1536, $offset);
    }

    public function testStreamSectorOffsetMiniStream(): void
    {
        $offset = Util::streamSectorOffset(3, $this->header, BigInteger::of(100), [7]);
        self::assertSame(((7 + 1) * $this->header->sectorSize) + 192, $offset);
    }

    public function testFatSectorSize(): void
    {
        self::assertSame(128, Util::fatSectorSize($this->header));
    }
}

