<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Mapi;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\Mapi\PropertyStreamEntry;
use Cosmira\OutlookMessage\Mapi\PropertyStreamReader;
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
}
