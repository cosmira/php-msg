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
    public function testChildLookupDistinguishesStoragesFromStreams(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();
        $storage = $builder->addStorage('Storage', $root);
        $builder->addStream('Stream', 'data', $root);

        $this->assertTrue($builder->hasChild('storage', $root));
        $this->assertSame($storage, $builder->findStorage('STORAGE', $root));
        $this->assertNull($builder->findStorage('Stream', $root));
        $this->assertFalse($builder->hasChild('Missing', $root));
    }

    public function testDeterministicCompoundFileSnapshots(): void
    {
        $empty = new CompoundBuilder();

        $mini = new CompoundBuilder();
        $miniRoot = $mini->rootIndex();
        $mini->addStream('Mini', 'abc', $miniRoot);
        $mini->addStorage('Folder', $miniRoot);

        $large = new CompoundBuilder();
        $large->addStream('Large', str_repeat('L', 9000), $large->rootIndex());

        $difat = new CompoundBuilder();
        $difat->addStream('Huge', str_repeat('X', 8 * 1024 * 1024), $difat->rootIndex());

        $this->assertSame('f146ebaabb3fed50fd4a54f4811e27f5dda67162cc25d4b3f5e181f70cf2d6b2', hash('sha256', $empty->build()));
        $this->assertSame('7be013181bbabb87306e72860f560cbf318c694b82db90b941db9a1a8e7dc9dd', hash('sha256', $mini->build()));
        $this->assertSame('a95e3c417a31a37073ef182bbf37a0f1e677c51c775166c4ce4ef52ff89fec7b', hash('sha256', $large->build()));
        $this->assertSame('c64e69896d7d2360bdeddf9252ecf8d76d59d4fe48825c45733daf1f61acdecb', hash('sha256', $difat->build()));
    }

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
