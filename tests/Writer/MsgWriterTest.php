<?php

declare(strict_types=1);

namespace MsgViewer\Tests\Writer;

use DateTimeImmutable;
use MsgViewer\MsgParser;
use MsgViewer\Writer\AttachmentDraft;
use MsgViewer\Writer\MessageDraft;
use MsgViewer\Writer\MsgWriter;
use MsgViewer\Writer\RecipientDraft;
use PHPUnit\Framework\TestCase;

final class MsgWriterTest extends TestCase
{
    public function testRoundTripWriteAndParse(): void
    {
        $draft = new MessageDraft(
            subject: 'Test Subject',
            senderName: 'Alice Sender',
            senderEmail: 'alice@example.com',
            bodyPlain: 'Hello world!',
            bodyHtml: '<p>Hello world!</p>',
            bodyRtf: "{\\rtf1\\ansi Hello world!}",
            headers: "X-Test: yes",
            date: new DateTimeImmutable('2024-01-01 10:00:00', new \DateTimeZone('UTC'))
        );

        $draft->addRecipient(new RecipientDraft('John Doe', 'john@example.com'));

        $attachment = new AttachmentDraft(
            fileName: 'test.txt',
            displayName: 'Test File',
            mimeType: 'text/plain',
            language: 'en',
            extension: '.txt',
            content: "Sample attachment content"
        );

        $draft->addAttachment($attachment);

        $binary = MsgWriter::write($draft);

        $message = MsgParser::parse($binary);

        self::assertSame('Test Subject', $message->content->subject);
        self::assertSame('Alice Sender', $message->content->senderName);
        self::assertSame('alice@example.com', $message->content->senderEmail);
        self::assertSame('Hello world!', $message->content->body);
        self::assertSame('<p>Hello world!</p>', $message->content->bodyHTML);
        self::assertSame("{\\rtf1\\ansi Hello world!}", $message->content->bodyRTF);

        self::assertNotNull($message->content->date);
        self::assertSame(
            $draft->date?->setTimezone(new \DateTimeZone('UTC'))->format('U'),
            $message->content->date?->setTimezone(new \DateTimeZone('UTC'))->format('U')
        );

        self::assertSame('john@example.com', $message->content->toRecipients);
        self::assertCount(1, $message->recipients);
        self::assertSame('John Doe', $message->recipients[0]->name);
        self::assertSame('john@example.com', $message->recipients[0]->email);

        self::assertCount(1, $message->attachments);
        $parsedAttachment = $message->attachments[0];
        self::assertSame('test.txt', $parsedAttachment->fileName);
        self::assertSame('Test File', $parsedAttachment->displayName);
        self::assertSame('text/plain', $parsedAttachment->mimeType);
        self::assertSame("Sample attachment content", $parsedAttachment->content);
    }
}

