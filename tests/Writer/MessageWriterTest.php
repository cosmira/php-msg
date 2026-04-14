<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\MessageParser;
use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Writer\AttachmentPayload;
use Cosmira\OutlookMessage\Writer\MessageBuilder;
use Cosmira\OutlookMessage\Writer\MessageWriter;
use Cosmira\OutlookMessage\Writer\RecipientPayload;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MessageWriterTest extends TestCase
{
    public function testRoundTripWriteAndParse(): void
    {
        $draft = new MessageBuilder(
            subject: 'Test Subject',
            senderName: 'Alexandr Chernyaev',
            senderEmail: 'bliz48rus@gmail.com',
            body: 'Hello world!',
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

        $this->assertSame('Test Subject', $message->content->subject);
        $this->assertSame('Alexandr Chernyaev', $message->content->senderName);
        $this->assertSame('bliz48rus@gmail.com', $message->content->senderEmail);
        $this->assertSame('Hello world!', $message->content->body);
        $this->assertSame('<p>Hello world!</p>', $message->content->bodyHtml);
        $this->assertSame('{\\rtf1\\ansi Hello world!}', $message->content->bodyRtf);

        $this->assertInstanceOf(DateTimeImmutable::class, $message->content->date);
        $this->assertSame($draft->date->setTimezone(new \DateTimeZone('UTC'))->format('U'), $message->content->date?->setTimezone(new \DateTimeZone('UTC'))->format('U'));

        $this->assertSame('john@example.com', $message->content->to);
        $this->assertCount(1, $message->recipients);
        $this->assertSame('John Doe', $message->recipients[0]->name);
        $this->assertSame('john@example.com', $message->recipients[0]->email);

        $this->assertCount(1, $message->attachments);
        $parsedAttachment = $message->attachments[0];
        $this->assertSame('test.txt', $parsedAttachment->fileName);
        $this->assertSame('Test File', $parsedAttachment->displayName);
        $this->assertSame('text/plain', $parsedAttachment->mimeType);
        $this->assertSame('Sample attachment content', $parsedAttachment->content);
    }

    public function testMinimalDraftWithoutRecipientsOrAttachments(): void
    {
        $draft = new MessageBuilder(subject: 'Subject Only');

        $binary = MessageWriter::write($draft);
        $message = MessageParser::parse($binary);

        $this->assertSame('Subject Only', $message->content->subject);
        $this->assertNull($message->content->senderName);
        $this->assertNull($message->content->body);
        $this->assertSame('', $message->content->to ?? '');
        $this->assertCount(0, $message->recipients);
        $this->assertCount(0, $message->attachments);
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

        $this->assertCount(1, $message->attachments);
        $this->assertSame($largeContent, $message->attachments[0]->content);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];
        $attachStorage = $compound->directory->get('__attach_version1.0_#00000000', $root->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $attachStorage);

        $contentStream = $compound->directory->get('__substg1.0_37010102', $attachStorage->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $contentStream);
        $this->assertTrue($contentStream->streamSize->isGreaterThan(4096));
        $this->assertLessThan(0xFFFFFFFE, $contentStream->startingSectorLocation);
    }

    public function testMultipleRecipientsAndAttachments(): void
    {
        $draft = new MessageBuilder(
            subject: 'Multi',
            senderName: 'Sender',
            senderEmail: 'sender@example.com',
            body: 'Body'
        );

        $draft->recipient(new RecipientPayload('Bob', 'bob@example.com'));
        $draft->recipient(new RecipientPayload('Alice', 'bliz48rus@gmail.com'));

        $draft->attachment(new AttachmentPayload(fileName: 'a.txt', content: 'AAA'));
        $draft->attachment(new AttachmentPayload(fileName: 'b.txt', content: 'BBB'));

        $binary = MessageWriter::write($draft);
        $message = MessageParser::parse($binary);

        $this->assertSame('bob@example.com;bliz48rus@gmail.com', $message->content->to);
        $this->assertCount(2, $message->recipients);
        $this->assertSame('Bob', $message->recipients[0]->name);
        $this->assertSame('Alice', $message->recipients[1]->name);

        $this->assertCount(2, $message->attachments);
        $this->assertSame('AAA', $message->attachments[0]->content);
        $this->assertSame('BBB', $message->attachments[1]->content);
    }

    public function testRootClsidIsMsgClsid(): void
    {
        $draft = new MessageBuilder(subject: 'CLSID Test');
        $binary = MessageWriter::write($draft);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];

        // MS-OXMSG §2.1: root CLSID must be {00020D0B-0000-0000-C000-000000000046}
        $this->assertSame('0b0d020000000000c000000000000046', $root->clsid);
    }

    public function testNameidStorageIsCreated(): void
    {
        $draft = new MessageBuilder(subject: 'Nameid Test');
        $binary = MessageWriter::write($draft);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];

        $nameid = $compound->directory->get('__nameid_version1.0', $root->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $nameid, '__nameid_version1.0 storage must exist per MS-OXMSG §2.1.1');
    }

    public function testRecipientTypeIsWrittenAndParsed(): void
    {
        $draft = new MessageBuilder(subject: 'Recipient Type Test');
        $draft->recipient(new RecipientPayload('Alice', 'alice@example.com', RecipientPayload::TO));
        $draft->recipient(new RecipientPayload('Bob', 'bob@example.com', RecipientPayload::CC));

        $binary = MessageWriter::write($draft);
        $message = MessageParser::parse($binary);

        $this->assertCount(2, $message->recipients);
        $this->assertSame(RecipientPayload::TO, $message->recipients[0]->type);
        $this->assertSame(RecipientPayload::CC, $message->recipients[1]->type);
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
        $this->assertInstanceOf(DirectoryEntry::class, $propertyEntry);

        $propertyStream = $compound->readStreamToString($propertyEntry);

        $buffer = new BinaryBuffer($propertyStream);
        $this->assertSame(2, $buffer->getUint32(8));  // nextRecipientId
        $this->assertSame(1, $buffer->getUint32(12)); // nextAttachmentId
        $this->assertSame(2, $buffer->getUint32(16)); // recipientCount
        $this->assertSame(1, $buffer->getUint32(20)); // attachmentCount

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

        $this->assertSame(65001, $codepage);

        $messageClassEntry = $compound->directory->get('__substg1.0_001a001f', $root->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $messageClassEntry);
        $messageClass = $compound->readStreamToString($messageClassEntry);
        $this->assertSame("I\0P\0M\0.\0N\0o\0t\0e\0\0\0", $messageClass);
    }

    public function testCcAndBccDisplayFieldsRoundTrip(): void
    {
        $draft = new MessageBuilder(subject: 'CC BCC Test');
        $draft->recipient(new RecipientPayload('Alice', 'alice@example.com', RecipientPayload::TO));
        $draft->recipient(new RecipientPayload('Bob', 'bob@example.com', RecipientPayload::CC));
        $draft->recipient(new RecipientPayload('Carol', 'carol@example.com', RecipientPayload::BCC));

        $binary = MessageWriter::make($draft);
        $message = MessageParser::parse($binary);

        $this->assertSame('alice@example.com', $message->content->to);
        $this->assertSame('bob@example.com', $message->content->cc);
        $this->assertSame('carol@example.com', $message->content->bcc);
    }

    public function testDisplayToUsesNameWhenNoEmail(): void
    {
        $draft = new MessageBuilder(subject: 'Name-Only Recipient');
        $draft->recipient(new RecipientPayload('No Email Person', null, RecipientPayload::TO));

        $binary = MessageWriter::make($draft);
        $message = MessageParser::parse($binary);

        $this->assertSame('No Email Person', $message->content->to);
    }

    public function testNameidStorageContainsRequiredStreams(): void
    {
        $draft = new MessageBuilder(subject: 'Nameid Streams Test');
        $binary = MessageWriter::make($draft);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];

        $nameid = $compound->directory->get('__nameid_version1.0', $root->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $nameid);

        foreach (['__substg1.0_00020102', '__substg1.0_00030102', '__substg1.0_00040102'] as $streamName) {
            $entry = $compound->directory->get($streamName, $nameid->childId, false);
            $this->assertInstanceOf(
                DirectoryEntry::class,
                $entry,
                sprintf("Required nameid stream '%s' must exist per MS-OXMSG §2.2.3", $streamName)
            );
        }
    }

    public function testEmbeddedMsgAttachmentRoundTrip(): void
    {
        $inner = new MessageBuilder(
            subject: 'Inner Message',
            senderName: 'Inner Sender',
            body: 'Inner body text'
        );

        $outer = new MessageBuilder(subject: 'Outer Message');
        $outer->embeddedMsg($inner, 'forwarded.msg');

        $binary = MessageWriter::make($outer);
        $message = MessageParser::parse($binary);

        $this->assertSame('Outer Message', $message->content->subject);
        $this->assertCount(1, $message->attachments);

        $attachment = $message->attachments[0];
        $this->assertSame('forwarded.msg', $attachment->displayName);
        $this->assertInstanceOf(Message::class, $attachment->embedded);
        $this->assertSame('Inner Message', $attachment->embedded->content->subject);
        $this->assertSame('Inner Sender', $attachment->embedded->content->senderName);
        $this->assertSame('Inner body text', $attachment->embedded->content->body);
    }

    public function testDifatOverflowLargeFile(): void
    {
        // >~7 MB of data forces DIFAT extension sectors (more than 109 FAT sectors needed).
        $largeContent = str_repeat('X', 8 * 1024 * 1024);

        $draft = new MessageBuilder(subject: 'DIFAT Test');
        $draft->attachment(new AttachmentPayload(
            fileName: 'huge.bin',
            displayName: 'huge.bin',
            content: $largeContent
        ));

        $binary = MessageWriter::make($draft);
        $message = MessageParser::parse($binary);

        $this->assertSame('DIFAT Test', $message->content->subject);
        $this->assertCount(1, $message->attachments);
        $this->assertSame($largeContent, $message->attachments[0]->content);
    }

    public function testEmbeddedMsgWithExtensionAndMimeType(): void
    {
        // Covers MessageWriter::buildEmbeddedMsgAttachment lines for extension and mimeType
        $inner = new MessageBuilder(subject: 'Inner');

        $attachment = new AttachmentPayload(
            fileName: 'nested.msg',
            displayName: 'Nested Message',
            mimeType: 'message/rfc822',
            extension: '.msg',
            embedded: $inner,
        );

        $outer = new MessageBuilder(subject: 'Outer');
        $outer->attachment($attachment);

        $binary = MessageWriter::make($outer);
        $message = MessageParser::parse($binary);

        $this->assertCount(1, $message->attachments);
        $this->assertSame('.msg', $message->attachments[0]->extension);
        $this->assertSame('message/rfc822', $message->attachments[0]->mimeType);
        $this->assertInstanceOf(Message::class, $message->attachments[0]->embedded);
    }

    public function testCompoundFileToStringReturnsJson(): void
    {
        $binary = MessageWriter::make(new MessageBuilder(subject: 'ToString Test'));
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        $json = (string) $compound;

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('header', $decoded);
        $this->assertArrayHasKey('fat', $decoded);
        $this->assertArrayHasKey('directory', $decoded);
    }

    public function testReadStreamToStringThrowsWhenExceedsMaxBytes(): void
    {
        $content = str_repeat('X', 100);
        $draft = new MessageBuilder(subject: 'Limit Test');
        $draft->attachment(new AttachmentPayload(fileName: 'big.txt', content: $content));

        $binary = MessageWriter::make($draft);
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        $root = $compound->directory->entries[0];
        $attach = $compound->directory->get('__attach_version1.0_#00000000', $root->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $attach);
        $contentEntry = $compound->directory->get('__substg1.0_37010102', $attach->childId, false);

        $this->assertInstanceOf(DirectoryEntry::class, $contentEntry);
        $this->assertGreaterThan(0, $contentEntry->streamSize->toInt());

        $this->expectException(CorruptedFileException::class);
        $this->expectExceptionMessage('Stream size exceeds maximum allowed');
        $compound->readStreamToString($contentEntry, 10); // content is 100 bytes
    }

    public function testRawPropertyWithUnknownTypeIdIsSkipped(): void
    {
        // typeId=0xFFFF is not in PropertyTypes MAP → PropertyTypes::get returns null → skipped (line 338)
        $unknownProp = new RawProperty('9F00', 0xFFFF, 'ignored');
        $builder = new MessageBuilder(subject: 'Unknown TypeId');
        $builder->rawProperty($unknownProp);

        $binary = MessageWriter::make($builder);
        $message = MessageParser::parse($binary);

        // The unknown property should be skipped (not appear in raw properties or crash)
        $this->assertSame('Unknown TypeId', $message->content->subject);
        $found = array_filter($message->getRawProperties(), fn (RawProperty $p) => $p->id === '9f00');
        $this->assertEmpty($found);
    }

    public function testRawPropertyConflictingWithKnownStreamIsSkipped(): void
    {
        // Two raw properties with the same id+type → second one finds stream already set → skipped (line 356)
        // Both are variable-size (PtypString 0x001F) so a stream entry is made for the first
        $prop1 = new RawProperty('9F30', 0x001F, 'First value');
        $prop2 = new RawProperty('9F30', 0x001F, 'Duplicate');
        $builder = new MessageBuilder(subject: 'Stream Duplicate');
        $builder->rawProperty($prop1)->rawProperty($prop2);

        // Should build without error; the second property is silently skipped
        $binary = MessageWriter::make($builder);
        $this->assertNotEmpty($binary);
    }
}
