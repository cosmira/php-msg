<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests;

use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\MessageContent;
use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Writer\MessageBuilder;
use Cosmira\OutlookMessage\Writer\MessageWriter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

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

    public function testGetRawPropertiesReturnsArray(): void
    {
        $raw = new RawProperty('1234', 0x001F, 'test value', 0);
        $content = new MessageContent(null, 'Subject', null, null, null, null, null, null, null, null);
        $message = new Message($content, [], [], [$raw]);

        $this->assertSame([$raw], $message->getRawProperties());
    }

    public function testGetPreferredBodyReturnsHtmlFirst(): void
    {
        $content = new MessageContent(null, null, null, null, 'plain text', '<b>html</b>', 'rtf body', null, null, null);
        $message = new Message($content, [], []);

        $this->assertSame('<b>html</b>', $message->getPreferredBody());
    }

    public function testGetPreferredBodyFallsBackToRtf(): void
    {
        $content = new MessageContent(null, null, null, null, 'plain text', null, 'rtf body', null, null, null);
        $message = new Message($content, [], []);

        $this->assertSame('rtf body', $message->getPreferredBody());
    }

    public function testGetPreferredBodyFallsBackToPlainText(): void
    {
        $content = new MessageContent(null, null, null, null, 'plain text', null, null, null, null, null);
        $message = new Message($content, [], []);

        $this->assertSame('plain text', $message->getPreferredBody());
    }

    public function testGetPreferredBodyReturnsNullWhenAllEmpty(): void
    {
        $content = new MessageContent(null, null, null, null, null, null, null, null, null, null);
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
            'X-Test: yes',
            'to@example.com',
            'cc@example.com',
            'bcc@example.com'
        );
        $message = new Message($content, [], []);

        $this->assertSame($date, $message->date());
        $this->assertSame('Subject', $message->subject());
        $this->assertSame('Sender', $message->senderName());
        $this->assertSame('sender@example.com', $message->senderEmail());
        $this->assertSame('Plain', $message->body());
        $this->assertSame('<p>Html</p>', $message->bodyHtml());
        $this->assertSame('{\\rtf1 Test}', $message->bodyRtf());
        $this->assertSame('X-Test: yes', $message->headers());
        $this->assertSame('to@example.com', $message->to());
        $this->assertSame('cc@example.com', $message->cc());
        $this->assertSame('bcc@example.com', $message->bcc());
    }
}
