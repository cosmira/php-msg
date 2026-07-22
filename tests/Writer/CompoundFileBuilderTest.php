<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\CompoundFile\Directory\ObjectType;
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
        $this->assertInstanceOf(DirectoryEntry::class, $miniEntry);
        $this->assertLessThan(4096, $miniEntry->streamSize->toInt());

        $miniContent = $compound->readStreamToString($miniEntry);
        $this->assertSame('mini', substr($miniContent, 0, 4));
    }

    public function testDirectoryStreamIsWrittenImmediatelyAfterHeader(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $builder->addStream('Mini', 'mini', $root);

        $binary = $builder->build();
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        $this->assertSame(0, $compound->header->firstDirSectorLocation);
        $this->assertSame("R\0o\0o\0t\0", substr($binary, 512, 8));
    }

    public function testMiniFatNotCreatedWhenAllStreamsLarge(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $builder->addStream('LargeOnly', str_repeat('A', 5000), $root);

        $binary = $builder->build();
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        $this->assertSame(0xFFFFFFFE, $compound->header->firstMiniFatSectorLocation);
        $this->assertSame(0, $compound->header->numberOfMiniFatSectors);
    }

    public function testAbsentDifatStartsAtEndOfChain(): void
    {
        $builder = new CompoundBuilder();
        $builder->addStream('Data', 'data', $builder->rootIndex());

        $compound = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));

        $this->assertSame(0xFFFFFFFE, $compound->header->firstDifatSectorLocation);
        $this->assertSame(0, $compound->header->numberOfDifatSectors);
    }

    public function testMiniStreamRoundTripsAcrossMultipleMiniSectors(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();
        $content = str_repeat('A', 130);

        $builder->addStream('MiniLong', $content, $root);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $entry = $compound->directory->get('MiniLong', $compound->directory->entries[0]->childId, false);

        $this->assertInstanceOf(DirectoryEntry::class, $entry);
        $this->assertSame($content, substr($compound->readStreamToString($entry), 0, strlen($content)));
        $this->assertGreaterThan(0, $compound->header->numberOfMiniFatSectors);
    }

    public function testSingleChildHasNoSiblingPointers(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();
        $builder->addStream('OnlyChild', 'data', $root);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $entry = $compound->directory->get('OnlyChild', $compound->directory->entries[0]->childId, false);

        $this->assertInstanceOf(DirectoryEntry::class, $entry);
        $this->assertSame(CompoundBuilder::NO_STREAM, $entry->leftSiblingId);
        $this->assertSame(CompoundBuilder::NO_STREAM, $entry->rightSiblingId);
    }

    public function testEmptyStreamStartsAtEndOfChain(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();
        $builder->addStream('Empty', '', $root);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $entry = $compound->directory->get('Empty', $compound->directory->entries[0]->childId, false);

        $this->assertInstanceOf(DirectoryEntry::class, $entry);
        $this->assertSame(0xFFFFFFFE, $entry->startingSectorLocation);
        $this->assertTrue($entry->streamSize->isZero());
    }

    public function testStorageStartingSectorIsZero(): void
    {
        $builder = new CompoundBuilder();
        $storageIndex = $builder->addStorage('Storage', $builder->rootIndex());
        $builder->addStream('Data', 'data', $storageIndex);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $storage = $compound->directory->get('Storage', $compound->directory->entries[0]->childId, false);

        $this->assertInstanceOf(DirectoryEntry::class, $storage);
        $this->assertSame(0, $storage->startingSectorLocation);
    }

    public function testUnallocatedDirectoryEntriesUseNoStreamPointers(): void
    {
        $builder = new CompoundBuilder();
        $builder->addStream('Data', 'data', $builder->rootIndex());

        $compound = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $unallocatedEntries = array_filter(
            $compound->directory->entries,
            static fn (DirectoryEntry $entry): bool => $entry->objectType === ObjectType::Unknown,
        );

        $this->assertCount(2, $unallocatedEntries);
        foreach ($unallocatedEntries as $entry) {
            $this->assertSame(CompoundBuilder::NO_STREAM, $entry->leftSiblingId);
            $this->assertSame(CompoundBuilder::NO_STREAM, $entry->rightSiblingId);
            $this->assertSame(CompoundBuilder::NO_STREAM, $entry->childId);
        }
    }

    public function testDirectoryEntryNameExceeding31CharsIsTruncated(): void
    {
        // A name of 32 ASCII chars + null-terminator = 33*2 = 66 bytes UTF-16LE > 64 bytes
        // → triggers DirectoryEntryData::serialize() name truncation (lines 465-466)
        $longName = str_repeat('A', 32); // 32 chars → 66 bytes UTF-16LE with null

        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();
        $builder->addStorage($longName, $root);

        $binary = $builder->build();
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $entryId = $compound->directory->entries[0]->childId;
        $entry = $compound->directory->entries[$entryId];

        $this->assertSame(str_repeat('A', 31), $entry->entryName);
        $this->assertSame(64, $entry->entryNameLength);
        $this->assertSame("\0\0", substr($binary, 512 + ($entryId * 128) + 62, 2));
    }
}
