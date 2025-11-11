<?php

declare(strict_types=1);

namespace MsgViewer\Tests\Writer;

use DateTimeImmutable;
use MsgViewer\CompoundFile\CompoundFile;
use MsgViewer\IO\BinaryBuffer;
use MsgViewer\MessageParser;
use MsgViewer\Writer\AttachmentPayload;
use MsgViewer\Writer\MessageBuilder;
use MsgViewer\Writer\MessageWriter;
use MsgViewer\Writer\RecipientPayload;
use PHPUnit\Framework\TestCase;

final class MessageWriterTest extends TestCase
{
    public function testRoundTripWriteAndParse(): void
    {
        $draft = new MessageBuilder(
            subject: 'Test Subject',
            senderName: 'Alexandr Chernyaev',
            senderEmail: 'bliz48rus@gmail.com',
            bodyPlain: 'Hello world!',
            bodyHtml: '<p>Hello world!</p>',
            bodyRtf: '{\\rtf1\\ansi Hello world!}',
            headers: 'X-Test: yes',
            date: new DateTimeImmutable('2024-01-01 10:00:00', new \DateTimeZone('UTC'))
        );

        $draft->recipient(new RecipientPayload('John Doe', 'john@example.com'));

        $attachment = new AttachmentPayload(
            fileName: 'test.txt',
            displayName: 'Test File',
            mimeType: 'text/plain',
            language: 'en',
            extension: '.txt',
            content: 'Sample attachment content'
        );

        $draft->attachment($attachment);

        $binary = MessageWriter::write($draft);

        $message = MessageParser::parse($binary);

        self::assertSame('Test Subject', $message->content->subject);
        self::assertSame('Alexandr Chernyaev', $message->content->senderName);
        self::assertSame('bliz48rus@gmail.com', $message->content->senderEmail);
        self::assertSame('Hello world!', $message->content->body);
        self::assertSame('<p>Hello world!</p>', $message->content->bodyHTML);
        self::assertSame('{\\rtf1\\ansi Hello world!}', $message->content->bodyRTF);

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
        self::assertSame('Sample attachment content', $parsedAttachment->content);
    }

    public function testMinimalDraftWithoutRecipientsOrAttachments(): void
    {
        $draft = new MessageBuilder(subject: 'Subject Only');

        $binary = MessageWriter::write($draft);
        $message = MessageParser::parse($binary);

        self::assertSame('Subject Only', $message->content->subject);
        self::assertNull($message->content->senderName);
        self::assertNull($message->content->body);
        self::assertSame('', $message->content->toRecipients ?? '');
        self::assertCount(0, $message->recipients);
        self::assertCount(0, $message->attachments);
    }

    public function testLargeAttachmentUsesRegularFatSectors(): void
    {
        $largeContent = str_repeat('A', 6000);

        $draft = new MessageBuilder(subject: 'Large Attachment');
        $draft->attachment(new AttachmentPayload(
            fileName: 'large.bin',
            displayName: 'large.bin',
            mimeType: 'application/octet-stream',
            content: $largeContent
        ));

        $binary = MessageWriter::write($draft);
        $message = MessageParser::parse($binary);

        self::assertCount(1, $message->attachments);
        self::assertSame($largeContent, $message->attachments[0]->content);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];
        $attachStorage = $compound->directory->get('__attach_version1.0_#00000000', $root->childId, false);
        self::assertNotNull($attachStorage);

        $contentStream = $compound->directory->get('__substg1.0_37010102', $attachStorage->childId, false);
        self::assertNotNull($contentStream);
        self::assertTrue($contentStream->streamSize->isGreaterThan(4096));
        self::assertLessThan(0xFFFFFFFE, $contentStream->startingSectorLocation);
    }

    public function testMultipleRecipientsAndAttachments(): void
    {
        $draft = new MessageBuilder(
            subject: 'Multi',
            senderName: 'Sender',
            senderEmail: 'sender@example.com',
            bodyPlain: 'Body'
        );

        $draft->recipient(new RecipientPayload('Bob', 'bob@example.com'));
        $draft->recipient(new RecipientPayload('Alice', 'bliz48rus@gmail.com'));

        $draft->attachment(new AttachmentPayload(fileName: 'a.txt', content: 'AAA'));
        $draft->attachment(new AttachmentPayload(fileName: 'b.txt', content: 'BBB'));

        $binary = MessageWriter::write($draft);
        $message = MessageParser::parse($binary);

        self::assertSame('bob@example.com;bliz48rus@gmail.com', $message->content->toRecipients);
        self::assertCount(2, $message->recipients);
        self::assertSame('Bob', $message->recipients[0]->name);
        self::assertSame('Alice', $message->recipients[1]->name);

        self::assertCount(2, $message->attachments);
        self::assertSame('AAA', $message->attachments[0]->content);
        self::assertSame('BBB', $message->attachments[1]->content);
    }

    public function testPropertyStreamContainsCountsAndCodepage(): void
    {
        $draft = new MessageBuilder(subject: 'Counts');
        $draft->recipient(new RecipientPayload('R1', 'r1@example.com'));
        $draft->recipient(new RecipientPayload('R2', 'r2@example.com'));
        $draft->attachment(new AttachmentPayload(fileName: 'one.txt', content: '1'));

        $binary = MessageWriter::write($draft);
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        $root = $compound->directory->entries[0];
        $propertyEntry = $compound->directory->get('__properties_version1.0', $root->childId, false);
        self::assertNotNull($propertyEntry);

        $propertyStream = $compound->readStreamToString($propertyEntry);

        $buffer = new BinaryBuffer($propertyStream);
        self::assertSame(2, $buffer->getUint32(8));  // nextRecipientId
        self::assertSame(1, $buffer->getUint32(12)); // nextAttachmentId
        self::assertSame(2, $buffer->getUint32(16)); // recipientCount
        self::assertSame(1, $buffer->getUint32(20)); // attachmentCount

        $offset = 32;
        $codepage = null;
        while ($offset + 16 <= strlen($propertyStream)) {
            $propertyTag = $buffer->getUint32($offset);
            if ($propertyTag === ((0x3FDE << 16) | 0x0003)) {
                $codepage = $buffer->getUint32($offset + 8);
                break;
            }
            $offset += 16;
        }

        self::assertSame(65001, $codepage);

        $messageClassEntry = $compound->directory->get('__substg1.0_001a001f', $root->childId, false);
        self::assertNotNull($messageClassEntry);
        $messageClass = $compound->readStreamToString($messageClassEntry);
        self::assertSame("I\0P\0M\0.\0N\0o\0t\0e\0\0\0", $messageClass);
    }
}
