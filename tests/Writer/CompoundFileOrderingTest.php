<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\ColorFlag;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
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

    public function testUnicodeDirectoryNamesUseTheSameOrderingForWritingAndLookup(): void
    {
        $builder = new CompoundBuilder();
        $names = ['a', 'é', '😀', 'aa', 'яя', 'bbb', '中'];

        foreach ($names as $name) {
            $builder->addStream($name, 'value', $builder->rootIndex());
        }

        $compound = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $root = $compound->directory->root();
        $this->assertInstanceOf(DirectoryEntry::class, $root);

        foreach ($names as $name) {
            $this->assertSame($name, $compound->directory->get($name, $root->childId, false)?->entryName);
        }
    }

    public function testGeneratedDirectoryTreesSatisfyRedBlackInvariants(): void
    {
        foreach (range(1, 100) as $count) {
            $builder = new CompoundBuilder();
            foreach (range(1, $count) as $index) {
                $builder->addStream(sprintf('stream-%03d', $index), 'value', $builder->rootIndex());
            }

            $compound = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
            $root = $compound->directory->root();
            $this->assertInstanceOf(DirectoryEntry::class, $root);
            $this->assertSame(ColorFlag::Black, $compound->directory->entries[$root->childId]->colorFlag);
            $this->assertRedBlackSubtree($compound, $root->childId);
        }
    }

    private function assertRedBlackSubtree(CompoundFile $compound, int $entryId): int
    {
        if ($entryId === 0xFFFFFFFF) {
            return 1;
        }

        $entry = $compound->directory->entries[$entryId];
        $leftHeight = $this->assertRedBlackSubtree($compound, $entry->leftSiblingId);
        $rightHeight = $this->assertRedBlackSubtree($compound, $entry->rightSiblingId);
        $this->assertSame($leftHeight, $rightHeight, $entry->entryName);

        if ($entry->colorFlag === ColorFlag::Red) {
            foreach ([$entry->leftSiblingId, $entry->rightSiblingId] as $childId) {
                if ($childId !== 0xFFFFFFFF) {
                    $this->assertSame(ColorFlag::Black, $compound->directory->entries[$childId]->colorFlag);
                }
            }
        }

        return $leftHeight + ($entry->colorFlag === ColorFlag::Black ? 1 : 0);
    }
}
