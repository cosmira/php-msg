<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\CompoundFile;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Difat;
use Cosmira\OutlookMessage\CompoundFile\Directory\ColorFlag;
use Cosmira\OutlookMessage\CompoundFile\Directory\Directory;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\CompoundFile\Directory\ObjectType;
use Cosmira\OutlookMessage\CompoundFile\Header;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Tests\Support\CompoundFileBuilder as HeaderBuilder;
use Cosmira\OutlookMessage\Writer\CompoundBuilder;
use Cosmira\OutlookMessage\Writer\DirectoryEntryData;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function testPrematureFatChainIsRejected(): void
    {
        $builder = new CompoundBuilder();
        $builder->addStream('Data', str_repeat('x', 5000), $builder->rootIndex());

        $parsed = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $entry = $parsed->directory->get('Data', $parsed->directory->entries[0]->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $entry);
        $broken = new CompoundFile($parsed->buffer, $parsed->header, $parsed->difat, [], $parsed->miniFat, $parsed->directory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ended before the declared stream size');

        $broken->readStream($entry, static function (): void {});
    }

    public function testVersionFourUsesItsDifatCapacity(): void
    {
        $headerBinary = HeaderBuilder::createHeaderBinary(sectorShift: 12);
        $headerBinary = substr_replace($headerBinary, pack('v', 4), 26, 2);

        $header = Header::parse(new BinaryBuffer($headerBinary));

        $this->assertSame([2], Difat::collect(new BinaryBuffer($headerBinary), $header));
    }

    public function testCircularDifatChainIsRejected(): void
    {
        $headerBinary = HeaderBuilder::createHeaderBinary();
        $headerBinary = substr_replace($headerBinary, pack('V', 0), 68, 4);
        $headerBinary = substr_replace($headerBinary, pack('V', 1), 72, 4);

        $difatSector = str_repeat("\xFF", 508).pack('V', 0);
        $buffer = new BinaryBuffer($headerBinary.$difatSector);
        $header = Header::parse($buffer);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular reference detected in DIFAT chain');

        Difat::collect($buffer, $header);
    }

    public function testCircularRootMiniStreamChainIsRejected(): void
    {
        $builder = new CompoundBuilder();
        $builder->addStream('Mini', 'payload', $builder->rootIndex());

        $parsed = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $root = $parsed->directory->root();
        $this->assertInstanceOf(DirectoryEntry::class, $root);

        $fat = $parsed->fat;
        $fat[$root->startingSectorLocation] = $root->startingSectorLocation;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular reference detected in root mini stream chain');

        Directory::load($parsed->buffer, $parsed->header, $fat);
    }

    public function testNegativeDirectoryStreamSizeIsSerializedAsZero(): void
    {
        // DirectoryEntryData is package-private and lives in CompoundBuilder.php.
        $builder = new CompoundBuilder();
        $this->assertSame(0, $builder->rootIndex());

        $entry = new DirectoryEntryData('Negative', ObjectType::Stream, ColorFlag::Black);
        $entry->streamSize = BigInteger::of(-1);

        $this->assertSame(str_repeat("\0", 8), substr($entry->serialize(), 120, 8));
    }
}
