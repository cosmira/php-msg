<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\RawProperty;
use PHPUnit\Framework\TestCase;

final class AttachmentTest extends TestCase
{
    public function testAttachmentDefaultsToNonInline(): void
    {
        $raw = new RawProperty('1234', 0x0003, 42, 0);
        $attachment = new Attachment(
            extension: '.txt',
            fileName: 'file.txt',
            mimeType: 'text/plain',
            language: 'en',
            displayName: 'file.txt',
            content: 'content',
            embedded: null,
            rawProperties: [$raw],
        );

        $this->assertFalse($attachment->isInline);
        $this->assertSame([$raw], $attachment->rawProperties());
    }

    public function testAttachmentMethodAliasesProxyProperties(): void
    {
        $raw = new RawProperty('1234', 0x0003, 42, 0);
        $attachment = new Attachment(
            extension: '.txt',
            fileName: 'file.txt',
            mimeType: 'text/plain',
            language: 'en',
            displayName: 'File',
            content: 'content',
            embedded: null,
            contentId: 'cid:file',
            isInline: true,
            rawProperties: [$raw],
        );

        $this->assertSame('.txt', $attachment->extension());
        $this->assertSame('file.txt', $attachment->fileName());
        $this->assertSame('text/plain', $attachment->mimeType());
        $this->assertSame('en', $attachment->language());
        $this->assertSame('File', $attachment->displayName());
        $this->assertSame('content', $attachment->content());
        $this->assertNotInstanceOf(Message::class, $attachment->embedded());
        $this->assertSame('cid:file', $attachment->contentId());
        $this->assertTrue($attachment->isInline());
        $this->assertSame([$raw], $attachment->rawProperties());
    }
}
