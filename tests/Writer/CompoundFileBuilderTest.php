<?php

declare(strict_types=1);

namespace MsgViewer\Tests\Writer;

use MsgViewer\CompoundFile\CompoundFile;
use MsgViewer\IO\BinaryBuffer;
use MsgViewer\Writer\CompoundBuilder;
use PHPUnit\Framework\TestCase;

final class CompoundFileBuilderTest extends TestCase
{
    public function testMiniFatIsCreatedForSmallStreams(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $builder->addStream('Mini', 'mini', $root);
        $builder->addStream('Large', str_repeat('L', 8192), $root);

        $binary = $builder->build();
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        self::assertNotSame(
            CompoundBuilder::NO_STREAM,
            $compound->header->firstMiniFatSectorLocation
        );
        self::assertGreaterThan(0, $compound->header->numberOfMiniFatSectors);

        $miniEntry = $compound->directory->get('Mini', $compound->directory->entries[0]->childId, false);
        self::assertNotNull($miniEntry);
        self::assertLessThan(4096, (int) $miniEntry->streamSize->toInt());

        $miniContent = $compound->readStreamToString($miniEntry);
        self::assertSame('mini', substr($miniContent, 0, 4));
    }

    public function testMiniFatNotCreatedWhenAllStreamsLarge(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $builder->addStream('LargeOnly', str_repeat('A', 5000), $root);

        $binary = $builder->build();
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        self::assertSame(
            CompoundBuilder::NO_STREAM,
            $compound->header->firstMiniFatSectorLocation
        );
        self::assertSame(0, $compound->header->numberOfMiniFatSectors);
    }
}

