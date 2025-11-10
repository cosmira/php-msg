<?php

declare(strict_types=1);

namespace MsgViewer\Tests\Writer;

use MsgViewer\CompoundFile\CompoundFile;
use MsgViewer\IO\BinaryBuffer;
use MsgViewer\Writer\AttachmentDraft;
use MsgViewer\Writer\MessageDraft;
use MsgViewer\Writer\MsgWriter;
use MsgViewer\Writer\CompoundFileBuilder;
use PHPUnit\Framework\TestCase;

final class MiniStreamAlignmentTest extends TestCase
{
    public function testMiniStreamIsMultipleOf64Bytes(): void
    {
        $draft = new MessageDraft(subject: 'Mini');
        $draft->addAttachment(new AttachmentDraft(fileName: 'mini.txt', content: 'abc'));

        $binary = MsgWriter::write($draft);
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        $root = $compound->directory->entries[0];
        $miniStreamEntry = $root;
        $size = (int) $miniStreamEntry->streamSize->toInt();

        self::assertNotSame(CompoundFileBuilder::NO_STREAM, $root->startingSectorLocation);
        self::assertSame(0, $size % 64);
    }
}

