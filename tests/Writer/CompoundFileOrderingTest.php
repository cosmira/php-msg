<?php

declare(strict_types=1);

namespace MsgViewer\Tests\Writer;

use MsgViewer\CompoundFile\CompoundFile;
use MsgViewer\Support\BinaryBuffer;
use MsgViewer\Writer\CompoundBuilder;
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

        self::assertSame('a', $a?->entryName);
        self::assertSame('AA', $aa?->entryName);
        self::assertSame('CC', $cc?->entryName);
        self::assertSame('bbb', $bbb?->entryName);
    }
}
