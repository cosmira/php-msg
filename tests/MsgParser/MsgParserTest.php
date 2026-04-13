<?php

declare(strict_types=1);

namespace MsgViewer\Tests\MsgParser;

use MsgViewer\MessageParser;
use MsgViewer\Writer\CompoundBuilder;
use PHPUnit\Framework\TestCase;

final class MsgParserTest extends TestCase
{
    public function testParsesAnsiStringsUsingDeclaredCodepage(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $header = str_repeat("\0", 8)
            .pack('V', 0) // nextRecipientId
            .pack('V', 0) // nextAttachmentId
            .pack('V', 0) // recipientCount
            .pack('V', 0) // attachmentCount
            .str_repeat("\0", 8);

        $ansiString = iconv('UTF-8', 'Windows-1251', 'Привет')."\0";

        $codepageEntry = pack('V', (0x3FDE << 16) | 0x0003)
            .pack('V', 0)
            .pack('V', 1251)
            .pack('V', 0);

        $subjectEntry = pack('V', (0x0037 << 16) | 0x001E)
            .pack('V', 0)
            .pack('V', strlen($ansiString))
            .pack('V', 0);

        $propertyStream = $header.$codepageEntry.$subjectEntry;

        $builder->addStream('__properties_version1.0', $propertyStream, $root);
        $builder->addStream('__substg1.0_0037001e', $ansiString, $root);

        $binary = $builder->build();
        $message = MessageParser::parse($binary);

        self::assertSame('Привет', $message->content->subject);
    }

    public function testParsesEmbeddedMsgObject(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        // Inner MSG subject as UTF-16LE
        $innerSubject = mb_convert_encoding('Inner Subject'."\0", 'UTF-16LE', 'UTF-8');

        // Inner MSG property stream (32-byte root header + subject entry)
        $innerPropStream = str_repeat("\0", 8)
            .pack('V', 0) // nextRecipientId
            .pack('V', 0) // nextAttachmentId
            .pack('V', 0) // recipientCount
            .pack('V', 0) // attachmentCount
            .str_repeat("\0", 8) // extra reserved (root header = 32 bytes total)
            .pack('V', (0x0037 << 16) | 0x001F).pack('V', 0).pack('V', strlen($innerSubject)).pack('V', 0);

        // Outer root property stream (1 attachment, no recipients)
        $outerPropStream = str_repeat("\0", 8)
            .pack('V', 0) // nextRecipientId
            .pack('V', 1) // nextAttachmentId
            .pack('V', 0) // recipientCount
            .pack('V', 1) // attachmentCount
            .str_repeat("\0", 8);

        $builder->addStream('__properties_version1.0', $outerPropStream, $root);

        $attachIdx = $builder->addStorage('__attach_version1.0_#00000000', $root);
        $builder->addStream('__properties_version1.0', str_repeat("\0", 8), $attachIdx);

        $embeddedMsgIdx = $builder->addStorage('__substg1.0_3701000d', $attachIdx);
        $builder->addStream('__properties_version1.0', $innerPropStream, $embeddedMsgIdx);
        $builder->addStream('__substg1.0_0037001f', $innerSubject, $embeddedMsgIdx);

        $message = MessageParser::parse($builder->build());

        self::assertCount(1, $message->attachments);
        $attachment = $message->attachments[0];
        self::assertNull($attachment->content);
        self::assertNotNull($attachment->embedded);
        self::assertSame('Inner Subject', $attachment->embedded->content->subject);
    }

    public function testParsesNestedEmbeddedMsgObjects(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        // Deepest (level 2) inner MSG subject
        $deepSubject = mb_convert_encoding('Deep Subject'."\0", 'UTF-16LE', 'UTF-8');

        $deepPropStream = str_repeat("\0", 8)
            .pack('V', 0).pack('V', 0).pack('V', 0).pack('V', 0)
            .str_repeat("\0", 8)
            .pack('V', (0x0037 << 16) | 0x001F).pack('V', 0).pack('V', strlen($deepSubject)).pack('V', 0);

        // Level 1 inner MSG (has 1 attachment = the level 2 inner MSG)
        $level1PropStream = str_repeat("\0", 8)
            .pack('V', 0).pack('V', 1).pack('V', 0).pack('V', 1)
            .str_repeat("\0", 8);

        // Outer root (has 1 attachment = level 1 inner MSG)
        $outerPropStream = str_repeat("\0", 8)
            .pack('V', 0).pack('V', 1).pack('V', 0).pack('V', 1)
            .str_repeat("\0", 8);

        $builder->addStream('__properties_version1.0', $outerPropStream, $root);

        // Level 1 attachment
        $attach1Idx = $builder->addStorage('__attach_version1.0_#00000000', $root);
        $builder->addStream('__properties_version1.0', str_repeat("\0", 8), $attach1Idx);

        // Level 1 embedded MSG storage
        $embedded1Idx = $builder->addStorage('__substg1.0_3701000d', $attach1Idx);
        $builder->addStream('__properties_version1.0', $level1PropStream, $embedded1Idx);

        // Level 2 attachment inside level 1 embedded MSG
        $attach2Idx = $builder->addStorage('__attach_version1.0_#00000000', $embedded1Idx);
        $builder->addStream('__properties_version1.0', str_repeat("\0", 8), $attach2Idx);

        // Level 2 embedded MSG storage
        $embedded2Idx = $builder->addStorage('__substg1.0_3701000d', $attach2Idx);
        $builder->addStream('__properties_version1.0', $deepPropStream, $embedded2Idx);
        $builder->addStream('__substg1.0_0037001f', $deepSubject, $embedded2Idx);

        $message = MessageParser::parse($builder->build());

        $level1 = $message->attachments[0]->embedded;
        self::assertNotNull($level1);
        self::assertCount(1, $level1->attachments);

        $level2 = $level1->attachments[0]->embedded;
        self::assertNotNull($level2);
        self::assertSame('Deep Subject', $level2->content->subject);

        // toArray() must reflect the full nesting tree
        $tree = $message->toArray();
        self::assertNull($tree['attachments'][0]['embedded']['subject']);
        self::assertSame('Deep Subject', $tree['attachments'][0]['embedded']['attachments'][0]['embedded']['subject']);
    }

    public function testRegularAttachmentHasNullEmbeddedMsg(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $content = 'Hello attachment';

        $attachPropStream = str_repeat("\0", 8)
            .pack('V', (0x3701 << 16) | 0x0102).pack('V', 0).pack('V', strlen($content)).pack('V', 0);

        $outerPropStream = str_repeat("\0", 8)
            .pack('V', 0).pack('V', 1).pack('V', 0).pack('V', 1)
            .str_repeat("\0", 8);

        $builder->addStream('__properties_version1.0', $outerPropStream, $root);

        $attachIdx = $builder->addStorage('__attach_version1.0_#00000000', $root);
        $builder->addStream('__properties_version1.0', $attachPropStream, $attachIdx);
        $builder->addStream('__substg1.0_37010102', $content, $attachIdx);

        $message = MessageParser::parse($builder->build());

        self::assertCount(1, $message->attachments);
        self::assertSame($content, $message->attachments[0]->content);
        self::assertNull($message->attachments[0]->embedded);
    }
}
