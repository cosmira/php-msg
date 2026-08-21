<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\CompoundFile;

use Cosmira\OutlookMessage\CompoundFile\PackedFatTable;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PackedFatTableTest extends TestCase
{
    /**
     * Ensure packed FAT entries retain integer access without expanding the complete table.
     */
    public function testReadsCountsMutatesAndSerializesPackedEntries(): void
    {
        $table = new PackedFatTable(pack('V*', 1, 0xFFFFFFFF, 7));

        $this->assertCount(3, $table);
        $this->assertTrue(isset($table[0]));
        $this->assertFalse(isset($table[-1]));
        $this->assertFalse(isset($table['0']));
        $this->assertFalse(isset($table[3]));
        $this->assertSame(1, $table[0]);
        $this->assertSame(0xFFFFFFFF, $table[1]);
        $this->assertNull($table[3]);

        $table[2] = 11;

        $this->assertSame(11, $table[2]);
        $this->assertSame([1, 0xFFFFFFFF, 11], $table->jsonSerialize());
        $this->assertSame([], (new PackedFatTable(''))->jsonSerialize());
    }

    /**
     * Ensure malformed packed input is rejected before it can corrupt sector traversal.
     */
    public function testRejectsAnIncompleteEntry(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Packed FAT data must contain complete 32-bit entries.');

        new PackedFatTable("\x01");
    }

    /**
     * Ensure callers cannot append entries through the fixed-size array interface.
     */
    public function testRejectsAnUnknownMutationOffset(): void
    {
        $table = new PackedFatTable(pack('V', 1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Packed FAT entries require an existing integer offset and value.');

        $table[1] = 2;
    }

    /**
     * Ensure callers cannot remove entries from the immutable packed table.
     */
    public function testRejectsEntryRemoval(): void
    {
        $table = new PackedFatTable(pack('V', 1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Packed FAT tables are immutable.');

        unset($table[0]);
    }
}
