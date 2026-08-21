<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\CompoundFile;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\ColorFlag;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\CompoundFile\Directory\ObjectType;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Writer\CompoundBuilder;
use Cosmira\OutlookMessage\Writer\CompoundFileBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CompoundFileTest extends TestCase
{
    public function testReadStreamIsCallableFromPublicApi(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();
        $builder->addStream('Data', 'abcdef', $root);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));
        $entry = $compound->directory->get('Data', $compound->directory->entries[0]->childId, false);

        $this->assertInstanceOf(DirectoryEntry::class, $entry);

        $chunks = '';
        $compound->readStream($entry, static function (int $offset, string $chunk) use (&$chunks): void {
            $chunks .= $chunk;
        });

        $this->assertSame('abcdef', substr($chunks, 0, 6));
    }

    public function testReadStreamRejectsNonEmptyStreamWithoutSectorChain(): void
    {
        $builder = new CompoundBuilder();
        $compound = CompoundFile::fromBinary(new BinaryBuffer($builder->build()));

        $entry = new DirectoryEntry(
            entryName: 'Broken',
            entryNameLength: 12,
            objectType: ObjectType::Stream,
            colorFlag: ColorFlag::Black,
            leftSiblingId: CompoundFileBuilder::NO_STREAM,
            rightSiblingId: CompoundFileBuilder::NO_STREAM,
            childId: CompoundFileBuilder::NO_STREAM,
            clsid: str_repeat("\0", 16),
            stateBits: 0,
            creationTime: BigInteger::zero(),
            modifiedTime: BigInteger::zero(),
            startingSectorLocation: 0xFFFFFFFE,
            streamSize: BigInteger::of(10),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Non-empty stream has no allocated sector chain');

        $compound->readStream($entry, static function (): void {});
    }
}
