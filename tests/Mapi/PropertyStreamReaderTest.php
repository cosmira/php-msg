<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Mapi;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\Mapi\PropertyData;
use Cosmira\OutlookMessage\Mapi\PropertyStreamEntry;
use Cosmira\OutlookMessage\Mapi\PropertyStreamReader;
use Cosmira\OutlookMessage\Mapi\PropertyTypes;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Writer\CompoundBuilder;
use PHPUnit\Framework\TestCase;

final class PropertyStreamReaderTest extends TestCase
{
    public function testForFolderParsesMultiplePropertyRecords(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $propertyStream = str_repeat("\0", 8)
            .pack('V', 0)
            .pack('V', 0)
            .pack('V', 0)
            .pack('V', 0)
            .str_repeat("\0", 8)
            .pack('V', (0x0E07 << 16) | 0x0003).pack('V', 0).pack('V', 8).pack('V', 0)
            .pack('V', (0x3FF1 << 16) | 0x0003).pack('V', 0).pack('V', 1049).pack('V', 0);

        $builder->addStream('__properties_version1.0', $propertyStream, $root);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $folder = $compound->directory->entries[0];

        $entry = PropertyStreamReader::forFolder($compound, $folder, true);

        $this->assertInstanceOf(PropertyStreamEntry::class, $entry);
        $this->assertCount(2, $entry->data);
        $this->assertSame(8, $entry->data['0e07']->valueOrSize);
        $this->assertSame(1049, $entry->data['3ff1']->valueOrSize);
    }

    public function testParsesEveryRegisteredPropertyTypeUsingItsRecordLayout(): void
    {
        PropertyTypes::init();
        $records = '';
        $expected = [];

        foreach (array_values(PropertyTypes::$MAP) as $index => $type) {
            $propertyId = 0x8000 + $index;
            $records .= pack('V', ($propertyId << 16) | $type->id)
                .pack('V', $index);

            if ($type->size === null || $type->multi) {
                $value = 0x100 + $index;
                $records .= pack('VV', $value, 0x67452301);
                $expected[$propertyId] = $value;
            } elseif ($type->size === 1) {
                $records .= pack('C', 0xA5).str_repeat("\0", 7);
                $expected[$propertyId] = 0xA5;
            } elseif ($type->size === 2) {
                $records .= pack('v', 0xBEEF).str_repeat("\0", 6);
                $expected[$propertyId] = 0xBEEF;
            } elseif ($type->size === 4) {
                $records .= pack('V', 0x89ABCDEF).pack('V', 0);
                $expected[$propertyId] = 0x89ABCDEF;
            } else {
                $records .= pack('V2', 0x89ABCDEF, 0x01234567);
                $expected[$propertyId] = '81985529216486895';
            }
        }

        $entry = $this->readRootProperties($records);

        $this->assertCount(count(PropertyTypes::$MAP), $entry->data);
        foreach (array_values(PropertyTypes::$MAP) as $index => $type) {
            $propertyId = 0x8000 + $index;
            $property = $entry->data[strtolower(dechex($propertyId))];
            $actual = $property->valueOrSize;

            $this->assertSame($type, $property->propertyType);
            $this->assertSame($index, $property->flags);
            $this->assertSame(
                $expected[$propertyId],
                $actual instanceof BigInteger ? $actual->toBase(10) : $actual,
            );
        }
    }

    public function testGuidRecordReadsTheDeclaredStreamSizeAndIgnoresReservedBytes(): void
    {
        $record = pack('V', (0x9000 << 16) | 0x0048)
            .pack('V', 0x00000006)
            .pack('V', 16)
            .pack('V', 0x67452301);

        $entry = $this->readRootProperties($record);
        $guid = $this->propertyById($entry, 0x9000);

        $this->assertSame(PropertyTypes::$PtypGuid, $guid->propertyType);
        $this->assertNull($guid->propertyType->size);
        $this->assertSame(16, $guid->valueOrSize);
    }

    public function testUnknownPropertyTypeKeepsItsTagAndDeclaredSize(): void
    {
        $record = pack('V', (0x9001 << 16) | 0x7777)
            .pack('V', 0x00000002)
            .pack('V', 123)
            .pack('V', 0x67452301);

        $property = $this->propertyById($this->readRootProperties($record), 0x9001);

        $this->assertSame(0x7777, $property->propertyType->id);
        $this->assertSame('Unknown', $property->propertyType->name);
        $this->assertSame(0x00000002, $property->flags);
        $this->assertSame(123, $property->valueOrSize);
    }

    private function readRootProperties(string $records): PropertyStreamEntry
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();
        $header = str_repeat("\0", 32);
        $builder->addStream('__properties_version1.0', $header.$records, $root);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $entry = PropertyStreamReader::forFolder($compound, $compound->directory->entries[0], true);

        $this->assertInstanceOf(PropertyStreamEntry::class, $entry);

        return $entry;
    }

    private function propertyById(PropertyStreamEntry $entry, int $id): PropertyData
    {
        foreach ($entry->data as $property) {
            if ($property->propertyId === $id) {
                return $property;
            }
        }

        throw new \LogicException(sprintf('Property %04X was not parsed.', $id));
    }
}
