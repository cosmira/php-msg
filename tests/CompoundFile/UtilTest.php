<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\CompoundFile;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\CompoundFile\Header;
use Cosmira\OutlookMessage\CompoundFile\Util;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Tests\Support\CompoundFileBuilder;
use PHPUnit\Framework\TestCase;

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
        $this->assertSame(512, Util::sectorOffset(0, $this->header->sectorSize));
        $this->assertSame(1024, Util::sectorOffset(1, $this->header->sectorSize));
    }

    public function testStreamSectorOffsetRegular(): void
    {
        $offset = Util::streamSectorOffset(2, $this->header, BigInteger::of(5000), []);
        $this->assertSame(1536, $offset);
    }

    public function testStreamSectorOffsetMiniStream(): void
    {
        $offset = Util::streamSectorOffset(3, $this->header, BigInteger::of(100), [7]);
        $this->assertSame(((7 + 1) * $this->header->sectorSize) + 192, $offset);
    }

    public function testFatSectorSize(): void
    {
        $this->assertSame(128, Util::fatSectorSize($this->header));
    }

    public function testSectorOffsetOverflowThrows(): void
    {
        $this->expectException(\Cosmira\OutlookMessage\Exception\CorruptedFileException::class);
        Util::sectorOffset(PHP_INT_MAX, 512);
    }

    public function testStreamSectorOffsetMiniStreamNullFallback(): void
    {
        // miniStreamLocations is empty → miniSector is null → returns raw mini offset
        $offset = Util::streamSectorOffset(0, $this->header, BigInteger::of(64), []);

        // When miniSector is null, returns $offset = $sector * $header->miniSectorSize = 0 * 64 = 0
        $this->assertSame(0, $offset);
    }
}
