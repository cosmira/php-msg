<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\AttachmentMethod;
use Cosmira\OutlookMessage\MessageParser;
use Cosmira\OutlookMessage\Writer\MessageBuilder;
use Cosmira\OutlookMessage\Writer\MessageWriter;
use PHPUnit\Framework\TestCase;

final class InlineAttachmentTest extends TestCase
{
    public function testInlineAttachmentFlagSurvivesRoundTrip(): void
    {
        $attachment = Attachment::fromData(str_repeat("\x89PNG", 16), 'image.png')
            ->withMime('image/png')
            ->inline('image001@example.com');

        $builder = MessageBuilder::make('HTML with inline image')
            ->attach($attachment);

        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        $this->assertCount(1, $parsed->attachments);
        $att = $parsed->attachments[0];

        $this->assertSame('image001@example.com', $att->contentId(), 'Content-ID must be preserved');
        $this->assertTrue($att->isInline(), 'isInline flag must survive round-trip');
        $this->assertSame('image/png', $att->mime());
        $this->assertSame('image.png', $att->name());
    }

    public function testNonInlineAttachmentHasFalseIsInline(): void
    {
        $attachment = Attachment::fromData('%PDF-1.4', 'document.pdf');

        $builder = MessageBuilder::make('Regular attachment')->attach($attachment);
        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        $this->assertCount(1, $parsed->attachments);
        $this->assertFalse($parsed->attachments[0]->isInline());
        $this->assertNull($parsed->attachments[0]->contentId());
    }

    public function testContentIdWithoutInlineFlagIsPreserved(): void
    {
        $attachment = new Attachment(
            fileName: 'doc.pdf',
            content: 'data',
            contentId: 'doc001@example.com',
            inline: false,
            method: AttachmentMethod::ByValue,
        );

        $builder = MessageBuilder::make('CID without inline')->attach($attachment);
        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        $att = $parsed->attachments[0];
        $this->assertSame('doc001@example.com', $att->contentId());
        $this->assertFalse($att->isInline());
    }

    public function testMultipleInlineAttachments(): void
    {
        $builder = MessageBuilder::make('Two inline images')
            ->attach(Attachment::fromData('imgdata1', 'a.png')->inline('a@cid'))
            ->attach(Attachment::fromData('imgdata2', 'b.png')->inline('b@cid'))
            ->attach(Attachment::fromData('text', 'regular.txt'));

        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        $this->assertCount(3, $parsed->attachments);
        $this->assertTrue($parsed->attachments[0]->isInline());
        $this->assertSame('a@cid', $parsed->attachments[0]->contentId());
        $this->assertTrue($parsed->attachments[1]->isInline());
        $this->assertSame('b@cid', $parsed->attachments[1]->contentId());
        $this->assertFalse($parsed->attachments[2]->isInline());
        $this->assertNull($parsed->attachments[2]->contentId());
    }
}
