<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\CompoundFile;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Difat;
use Cosmira\OutlookMessage\CompoundFile\Directory\ColorFlag;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\CompoundFile\Directory\ObjectType;
use Cosmira\OutlookMessage\CompoundFile\Header;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Tests\Support\CompoundFileBuilder as HeaderBuilder;
use Cosmira\OutlookMessage\Writer\CompoundBuilder;
use Cosmira\OutlookMessage\Writer\DirectoryEntryData;
use PHPUnit\Framework\TestCase;

final class CoverageTest extends TestCase
{
    public function testStreamHeaderCanConsumeBytes(): void
    {
        $builder = new CompoundBuilder();
        $builder->addStream('Data', 'abcdef', $builder->rootIndex());

        $compound = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $entry = $compound->directory->get('Data', $compound->directory->entries[0]->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $entry);

        $actual = '';
        $compound->readStream(
            $entry,
            static function (int $offset, string $chunk) use (&$actual): void {
                $actual .= $chunk;
            },
            onHeader: static fn (int $offset): int => 1,
        );

        $this->assertSame('bcdef', $actual);
    }

    public function testPrematureFatChainStopsReading(): void
    {
        $builder = new CompoundBuilder();
        $builder->addStream('Data', str_repeat('x', 5000), $builder->rootIndex());

        $parsed = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $entry = $parsed->directory->get('Data', $parsed->directory->entries[0]->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $entry);
        $broken = new CompoundFile($parsed->buffer, $parsed->header, $parsed->difat, [], $parsed->miniFat, $parsed->directory);

        $actual = '';
        $broken->readStream($entry, static function (int $offset, string $chunk) use (&$actual): void {
            $actual .= $chunk;
        });

        $this->assertSame(512, strlen($actual));
    }

    public function testVersionFourUsesItsDifatCapacity(): void
    {
        $headerBinary = HeaderBuilder::createHeaderBinary(sectorShift: 12);
        $headerBinary = substr_replace($headerBinary, pack('v', 4), 26, 2);

        $header = Header::parse(new BinaryBuffer($headerBinary));

        $this->assertSame([2], Difat::collect(new BinaryBuffer($headerBinary), $header));
    }

    public function testNegativeDirectoryStreamSizeIsSerializedAsZero(): void
    {
        // DirectoryEntryData is package-private and lives in CompoundBuilder.php.
        new CompoundBuilder();

        $entry = new DirectoryEntryData('Negative', ObjectType::Stream, ColorFlag::Black);
        $entry->streamSize = BigInteger::of(-1);

        $this->assertSame(str_repeat("\0", 8), substr($entry->serialize(), 120, 8));
    }
}
