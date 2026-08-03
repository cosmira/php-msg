<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Writer\CompoundFileBuilder;
use Cosmira\OutlookMessage\Writer\MessageBuilder;
use Cosmira\OutlookMessage\Writer\MessageWriter;
use PHPUnit\Framework\TestCase;

final class MiniStreamAlignmentTest extends TestCase
{
    public function testMiniStreamIsMultipleOf64Bytes(): void
    {
        $draft = new MessageBuilder(subject: 'Mini');
        $draft->attach(Attachment::fromData('abc', 'mini.txt'));

        $binary = MessageWriter::write($draft);
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        $root = $compound->directory->entries[0];
        $miniStreamEntry = $root;
        $size = $miniStreamEntry->streamSize->toInt();

        $this->assertNotSame(CompoundFileBuilder::NO_STREAM, $root->startingSectorLocation);
        $this->assertSame(0, $size % 64);
    }
}
