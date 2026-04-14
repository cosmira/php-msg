<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Mapi;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\Mapi\EntryStreamReader;
use Cosmira\OutlookMessage\Mapi\PropertyKind;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Writer\CompoundBuilder;
use PHPUnit\Framework\TestCase;

final class EntryStreamReaderTest extends TestCase
{
    public function testReadReturnsNullWhenRootEntryMissing(): void
    {
        // Patch firstDirSectorLocation to 0xFFFFFFFE so Directory::load produces empty entries
        $builder = new CompoundBuilder();
        $binary = $builder->build();

        // firstDirSectorLocation is at bytes 48-51 in the CFB header
        $patched = substr_replace($binary, pack('V', 0xFFFFFFFE), 48, 4);

        $file = CompoundFile::fromBinary(new BinaryBuffer($patched));
        $result = EntryStreamReader::read($file);

        $this->assertNull($result);
    }

    public function testReadReturnsNullWhenNameidFolderMissing(): void
    {
        $builder = new CompoundBuilder();
        // No __nameid_version1.0 storage added
        $root = $builder->rootIndex();
        $builder->addStream('__properties_version1.0', str_repeat("\0", 32), $root);

        $file = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $result = EntryStreamReader::read($file);

        $this->assertNull($result);
    }

    public function testReadReturnsNullWhenEntryStreamMissing(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();
        $builder->addStream('__properties_version1.0', str_repeat("\0", 32), $root);
        $nameid = $builder->addStorage('__nameid_version1.0', $root);
        // Add the GUID stream but not the entry stream
        $builder->addStream('__substg1.0_00020102', '', $nameid);
        $builder->addStream('__substg1.0_00040102', '', $nameid);
        // Missing __substg1.0_00030102

        $file = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $result = EntryStreamReader::read($file);

        $this->assertNull($result);
    }

    public function testReadReturnsEmptyArrayForEmptyStream(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();
        $builder->addStream('__properties_version1.0', str_repeat("\0", 32), $root);
        $nameid = $builder->addStorage('__nameid_version1.0', $root);
        $builder->addStream('__substg1.0_00020102', '', $nameid);
        $builder->addStream('__substg1.0_00030102', '', $nameid); // empty entry stream
        $builder->addStream('__substg1.0_00040102', '', $nameid);

        $file = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $result = EntryStreamReader::read($file);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testReadReturnsRecordsFromStream(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();
        $builder->addStream('__properties_version1.0', str_repeat("\0", 32), $root);
        $nameid = $builder->addStorage('__nameid_version1.0', $root);
        $builder->addStream('__substg1.0_00020102', '', $nameid);

        // One 8-byte record: nameIdOrStringOffset(4), guidWithKind(2), propertyIndex(2)
        // guidIndex = guidWithKind >> 1 = 3 >> 1 = 1
        // kind = (guidWithKind & 1) = 1 → PropertyKind::String
        $record = pack('V', 42)       // nameIdOrStringOffset
            .pack('v', 0x0003)        // guidWithKind: guidIndex=1, kind=String
            .pack('v', 7);            // propertyIndex

        $builder->addStream('__substg1.0_00030102', $record, $nameid);
        $builder->addStream('__substg1.0_00040102', '', $nameid);

        $file = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $result = EntryStreamReader::read($file);

        $this->assertNotNull($result);
        $this->assertCount(1, $result);

        $entry = $result[0];
        $this->assertSame(42, $entry->nameIdOrStringOffset);
        $this->assertSame(7, $entry->propertyIndex);
        $this->assertSame(1, $entry->guidIndex);
        $this->assertSame(PropertyKind::String, $entry->propertyKind);
    }

    public function testReadReturnsNumericalKindWhenBitZero(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();
        $builder->addStream('__properties_version1.0', str_repeat("\0", 32), $root);
        $nameid = $builder->addStorage('__nameid_version1.0', $root);
        $builder->addStream('__substg1.0_00020102', '', $nameid);

        // guidWithKind: guidIndex=2, kind=Numerical (bit 0 = 0)
        $record = pack('V', 100)
            .pack('v', 0x0004)  // guidWithKind: guidIndex=2, kind=Numerical
            .pack('v', 3);

        $builder->addStream('__substg1.0_00030102', $record, $nameid);
        $builder->addStream('__substg1.0_00040102', '', $nameid);

        $file = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $result = EntryStreamReader::read($file);

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertSame(PropertyKind::Numerical, $result[0]->propertyKind);
        $this->assertSame(2, $result[0]->guidIndex);
    }
}
