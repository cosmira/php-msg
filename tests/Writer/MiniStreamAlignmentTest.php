<?php

declare(strict_types=1);

namespace MsgViewer\Tests\Writer;

use MsgViewer\CompoundFile\CompoundFile;
use MsgViewer\Support\BinaryBuffer;
use MsgViewer\Writer\AttachmentPayload;
use MsgViewer\Writer\CompoundBuilder;
use MsgViewer\Writer\MessageBuilder;
use MsgViewer\Writer\MessageWriter;
use PHPUnit\Framework\TestCase;

final class MiniStreamAlignmentTest extends TestCase
{
    public function testMiniStreamIsMultipleOf64Bytes(): void
    {
        $draft = new MessageBuilder(subject: 'Mini');
        $draft->attachment(new AttachmentPayload(fileName: 'mini.txt', content: 'abc'));

        $binary = MessageWriter::write($draft);
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        $root = $compound->directory->entries[0];
        $miniStreamEntry = $root;
        $size = (int) $miniStreamEntry->streamSize->toInt();

        $this->assertNotSame(CompoundBuilder::NO_STREAM, $root->startingSectorLocation);
        $this->assertSame(0, $size % 64);
    }
}
