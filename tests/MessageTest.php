<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\MessageContent;
use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Recipient;
use Cosmira\OutlookMessage\Writer\MessageBuilder;
use Cosmira\OutlookMessage\Writer\MessageWriter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MessageTest extends TestCase
{
    public function testParseViaStaticMethod(): void
    {
        $binary = MessageWriter::make(new MessageBuilder(subject: 'Hello'));
        $message = Message::parse($binary);

        $this->assertSame('Hello', $message->content->subject);
    }

    public function testMakeReturnsBuilderAlias(): void
    {
        $builder = Message::make('Subject', 'Sender', 'sender@example.com');

        $this->assertSame('Subject', $builder->subject);
        $this->assertSame('Sender', $builder->senderName);
        $this->assertSame('sender@example.com', $builder->senderEmail);
    }

    public function testFromPathAndSaveRoundTripAFile(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'outlook-msg-source-');
        $target = tempnam(sys_get_temp_dir(), 'outlook-msg-target-');
        $this->assertIsString($source);
        $this->assertIsString($target);
        $binary = Message::make('From path')->toBinary();
        file_put_contents($source, $binary);

        try {
            $message = Message::fromPath($source);
            $this->assertSame($message, $message->save($target));
            $this->assertSame($binary, file_get_contents($target));
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    public function testFromPathRetainsTheOpenedFileWhenItsPathIsReplaced(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'outlook-msg-source-');
        $replacement = tempnam(sys_get_temp_dir(), 'outlook-msg-replacement-');
        $target = tempnam(sys_get_temp_dir(), 'outlook-msg-target-');
        $this->assertIsString($source);
        $this->assertIsString($replacement);
        $this->assertIsString($target);
        file_put_contents($source, Message::make('Original')->toBinary());
        file_put_contents($replacement, Message::make('Replacement')->toBinary());

        try {
            $message = Message::fromPath($source);
            rename($replacement, $source);
            $message->save($target);

            $this->assertSame('Original', $message->subject());
            $this->assertSame('Original', Message::fromPath($target)->subject());
        } finally {
            @unlink($source);
            @unlink($replacement);
            @unlink($target);
        }
    }

    public function testFromPathRejectsAnUnreadableFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read message');
        Message::fromPath('/missing/outlook-message.msg');
    }

    public function testSaveRejectsAnUnwritableTarget(): void
    {
        $message = Message::from(Message::make('Cannot save')->toBinary());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to write message');
        $message->save('/missing/outlook-message.msg');
    }

    public function testGetRawPropertiesReturnsArray(): void
    {
        $raw = new RawProperty('1234', 0x001F, 'test value', 0);
        $content = new MessageContent(null, 'Subject', null, null, null, null, null, null);
        $message = new Message($content, [], [], [$raw]);

        $this->assertSame([$raw], $message->getRawProperties());
    }

    public function testGetPreferredBodyReturnsHtmlFirst(): void
    {
        $content = new MessageContent(null, null, null, null, 'plain text', '<b>html</b>', 'rtf body', null);
        $message = new Message($content, [], []);

        $this->assertSame('<b>html</b>', $message->getPreferredBody());
    }

    public function testGetPreferredBodyFallsBackToRtf(): void
    {
        $content = new MessageContent(null, null, null, null, 'plain text', null, 'rtf body', null);
        $message = new Message($content, [], []);

        $this->assertSame('rtf body', $message->getPreferredBody());
    }

    public function testGetPreferredBodyFallsBackToPlainText(): void
    {
        $content = new MessageContent(null, null, null, null, 'plain text', null, null, null);
        $message = new Message($content, [], []);

        $this->assertSame('plain text', $message->getPreferredBody());
    }

    public function testGetPreferredBodyReturnsNullWhenAllEmpty(): void
    {
        $content = new MessageContent(null, null, null, null, null, null, null, null);
        $message = new Message($content, [], []);

        $this->assertNull($message->getPreferredBody());
    }

    public function testConvenienceAccessorsProxyMessageContent(): void
    {
        $date = new DateTimeImmutable('2024-01-01 10:00:00');
        $content = new MessageContent(
            $date,
            'Subject',
            'Sender',
            'sender@example.com',
            'Plain',
            '<p>Html</p>',
            '{\\rtf1 Test}',
            'X-Test: yes'
        );
        $message = new Message($content, [], [
            new Recipient('To', 'to@example.com', Recipient::TYPE_TO),
            new Recipient('Cc', 'cc@example.com', Recipient::TYPE_CC),
            new Recipient('Bcc', 'bcc@example.com', Recipient::TYPE_BCC),
        ]);

        $this->assertSame($date, $message->date());
        $this->assertSame('Subject', $message->subject());
        $this->assertSame('Sender', $message->senderName());
        $this->assertSame('sender@example.com', $message->senderEmail());
        $this->assertSame('Plain', $message->body());
        $this->assertSame('<p>Html</p>', $message->bodyHtml());
        $this->assertSame('{\\rtf1 Test}', $message->bodyRtf());
        $this->assertSame('X-Test: yes', $message->headers());
        $this->assertSame('to@example.com', $message->displayTo());
        $this->assertSame('cc@example.com', $message->displayCc());
        $this->assertSame('bcc@example.com', $message->displayBcc());
    }

    public function testCollectionAccessorsProxyCollections(): void
    {
        $to = new Recipient('Jane', 'jane@example.com', 1);
        $cc = new Recipient('John', 'john@example.com', 2);
        $bcc = new Recipient('Ops', 'ops@example.com', 3);
        $attachment = new Attachment('.txt', 'file.txt', 'text/plain', 'en', 'file.txt', 'body');
        $message = new Message(
            new MessageContent(null, null, null, null, null, null, null, null),
            [$attachment],
            [$to, $cc, $bcc]
        );

        $this->assertSame([$attachment], $message->attachments()->all());
        $this->assertSame([$to, $cc, $bcc], $message->recipients()->all());
        $this->assertSame([$to], $message->to()->all());
        $this->assertSame([$cc], $message->cc()->all());
        $this->assertSame([$bcc], $message->bcc()->all());
        $this->assertSame($message->content, $message->content());
    }
}
