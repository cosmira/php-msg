<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Writer\CompoundBuilder;
use PHPUnit\Framework\TestCase;

final class CompoundFileOrderingTest extends TestCase
{
    public function testDirectoryEntriesSortedByLengthThenCaseInsensitive(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $builder->addStream('bbb', '1', $root);
        $builder->addStream('AA', '1', $root);
        $builder->addStream('a', '1', $root);
        $builder->addStream('CC', '1', $root);

        $binary = $builder->build();
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        $dir = $compound->directory;
        $rootChild = $dir->entries[0]->childId;

        $a = $dir->get('a', $rootChild, false);
        $aa = $dir->get('AA', $rootChild, false);
        $cc = $dir->get('cc', $rootChild, false); // case-insensitive lookup
        $bbb = $dir->get('bbb', $rootChild, false);

        $this->assertSame('a', $a?->entryName);
        $this->assertSame('AA', $aa?->entryName);
        $this->assertSame('CC', $cc?->entryName);
        $this->assertSame('bbb', $bbb?->entryName);
    }
}
