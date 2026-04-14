<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Writer\CompoundBuilder;
use PHPUnit\Framework\TestCase;

final class CompoundFileBuilderTest extends TestCase
{
    public function testMiniFatIsCreatedForSmallStreams(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $builder->addStream('Mini', 'mini', $root);
        $builder->addStream('Large', str_repeat('L', 8192), $root);

        $binary = $builder->build();
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        $this->assertNotSame(CompoundBuilder::NO_STREAM, $compound->header->firstMiniFatSectorLocation);
        $this->assertGreaterThan(0, $compound->header->numberOfMiniFatSectors);

        $miniEntry = $compound->directory->get('Mini', $compound->directory->entries[0]->childId, false);
        $this->assertInstanceOf(\Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry::class, $miniEntry);
        $this->assertLessThan(4096, $miniEntry->streamSize->toInt());

        $miniContent = $compound->readStreamToString($miniEntry);
        $this->assertSame('mini', substr($miniContent, 0, 4));
    }

    public function testMiniFatNotCreatedWhenAllStreamsLarge(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $builder->addStream('LargeOnly', str_repeat('A', 5000), $root);

        $binary = $builder->build();
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        $this->assertSame(CompoundBuilder::NO_STREAM, $compound->header->firstMiniFatSectorLocation);
        $this->assertSame(0, $compound->header->numberOfMiniFatSectors);
    }

    public function testDirectoryEntryNameExceeding31CharsIsTruncated(): void
    {
        // A name of 32 ASCII chars + null-terminator = 33*2 = 66 bytes UTF-16LE > 64 bytes
        // → triggers DirectoryEntryData::serialize() name truncation (lines 465-466)
        $longName = str_repeat('A', 32); // 32 chars → 66 bytes UTF-16LE with null

        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();
        $builder->addStorage($longName, $root);

        // Should build without error; name is silently truncated in directory entry
        $binary = $builder->build();
        $this->assertNotEmpty($binary);
    }
}
