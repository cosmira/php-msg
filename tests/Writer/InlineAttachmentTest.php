<?php

declare(strict_types=1);

namespace MsgViewer\Tests\Writer;

use MsgViewer\MessageParser;
use MsgViewer\Writer\AttachmentPayload;
use MsgViewer\Writer\MessageBuilder;
use MsgViewer\Writer\MessageWriter;
use PHPUnit\Framework\TestCase;

final class InlineAttachmentTest extends TestCase
{
    public function testInlineAttachmentFlagSurvivesRoundTrip(): void
    {
        $attachment = new AttachmentPayload(
            fileName: 'image.png',
            displayName: 'image.png',
            mimeType: 'image/png',
            content: str_repeat("\x89PNG", 16),
            contentId: 'image001@example.com',
            isInline: true,
        );

        $builder = MessageBuilder::make('HTML with inline image')
            ->attachment($attachment);

        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        $this->assertCount(1, $parsed->attachments);
        $att = $parsed->attachments[0];

        $this->assertSame('image001@example.com', $att->contentId, 'Content-ID must be preserved');
        $this->assertTrue($att->isInline, 'isInline flag must survive round-trip');
        $this->assertSame('image/png', $att->mimeType);
        $this->assertSame('image.png', $att->fileName);
    }

    public function testNonInlineAttachmentHasFalseIsInline(): void
    {
        $attachment = new AttachmentPayload(
            fileName: 'document.pdf',
            content: '%PDF-1.4',
        );

        $builder = MessageBuilder::make('Regular attachment')->attachment($attachment);
        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        $this->assertCount(1, $parsed->attachments);
        $this->assertFalse($parsed->attachments[0]->isInline);
        $this->assertNull($parsed->attachments[0]->contentId);
    }

    public function testContentIdWithoutInlineFlagIsPreserved(): void
    {
        $attachment = new AttachmentPayload(
            fileName: 'doc.pdf',
            content: 'data',
            contentId: 'doc001@example.com',
            isInline: false,
        );

        $builder = MessageBuilder::make('CID without inline')->attachment($attachment);
        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        $att = $parsed->attachments[0];
        $this->assertSame('doc001@example.com', $att->contentId);
        $this->assertFalse($att->isInline);
    }

    public function testMultipleInlineAttachments(): void
    {
        $builder = MessageBuilder::make('Two inline images')
            ->attachment(new AttachmentPayload(
                fileName: 'a.png',
                content: 'imgdata1',
                contentId: 'a@cid',
                isInline: true,
            ))
            ->attachment(new AttachmentPayload(
                fileName: 'b.png',
                content: 'imgdata2',
                contentId: 'b@cid',
                isInline: true,
            ))
            ->attachment(new AttachmentPayload(
                fileName: 'regular.txt',
                content: 'text',
                isInline: false,
            ));

        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        $this->assertCount(3, $parsed->attachments);
        $this->assertTrue($parsed->attachments[0]->isInline);
        $this->assertSame('a@cid', $parsed->attachments[0]->contentId);
        $this->assertTrue($parsed->attachments[1]->isInline);
        $this->assertSame('b@cid', $parsed->attachments[1]->contentId);
        $this->assertFalse($parsed->attachments[2]->isInline);
        $this->assertNull($parsed->attachments[2]->contentId);
    }
}
