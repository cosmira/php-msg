<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\AttachmentMethod;
use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\MessageParser;
use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Writer\AttachmentStorageMetadata;
use Cosmira\OutlookMessage\Writer\MessageBuilder;
use Cosmira\OutlookMessage\Writer\MessageWriter;
use Cosmira\OutlookMessage\Writer\RecipientPayload;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MessageWriterTest extends TestCase
{
    public function testAttachmentOrderAndRenderingPositionSurviveRoundTrip(): void
    {
        $first = Attachment::fromData('first', 'first.txt');
        AttachmentStorageMetadata::rememberRenderingPosition($first, 42);
        $builder = Message::make('Metadata')
            ->attach($first)
            ->attach(Attachment::fromData('second', 'second.txt'));

        $message = Message::from($builder->toBinary());
        $roundTripped = Message::from($message->toBinary());

        $this->assertSame(
            ['first.txt', 'second.txt'],
            $roundTripped->attachments()->map(static fn (Attachment $attachment): ?string => $attachment->name())->all(),
        );
        $firstRoundTripped = $roundTripped->attachments()->first();
        $this->assertInstanceOf(Attachment::class, $firstRoundTripped);
        $this->assertSame(42, AttachmentStorageMetadata::renderingPosition($firstRoundTripped));
    }

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

        $attachment = new Attachment(
            extension: '.txt',
            fileName: 'test.txt',
            mimeType: 'text/plain',
            language: 'en',
            displayName: 'Test File',
            content: 'Sample attachment content',
            method: AttachmentMethod::ByValue,
        );

        $draft->attach($attachment);

        $binary = MessageWriter::write($draft);

        $message = MessageParser::parse($binary);

        $this->assertSame('Test Subject', $message->content->subject);
        $this->assertSame('Alexandr Chernyaev', $message->content->senderName);
        $this->assertSame('bliz48rus@gmail.com', $message->content->senderEmail);
        $this->assertSame('Hello world!', $message->content->body);
        $this->assertSame('<p>Hello world!</p>', $message->content->bodyHtml);
        $this->assertSame('{\\rtf1\\ansi Hello world!}', $message->content->bodyRtf);

        $parsedDate = $message->content->date;
        throw_unless($parsedDate instanceof DateTimeImmutable, \LogicException::class, 'Parsed date should be a DateTimeImmutable instance.');

        $draftDate = $draft->date;
        throw_unless($draftDate instanceof DateTimeImmutable, \LogicException::class, 'Draft date should be a DateTimeImmutable instance.');

        $this->assertSame($draftDate->setTimezone(new \DateTimeZone('UTC'))->format('U'), $parsedDate->setTimezone(new \DateTimeZone('UTC'))->format('U'));

        $this->assertSame('john@example.com', $message->displayTo());
        $this->assertCount(1, $message->recipients);
        $this->assertSame('John Doe', $message->recipients[0]->name);
        $this->assertSame('john@example.com', $message->recipients[0]->email);

        $this->assertCount(1, $message->attachments);
        $parsedAttachment = $message->attachments[0];
        $this->assertSame('test.txt', $parsedAttachment->name());
        $this->assertSame('Test File', $parsedAttachment->displayName());
        $this->assertSame('text/plain', $parsedAttachment->mime());
        $this->assertSame('Sample attachment content', $parsedAttachment->data());
    }

    public function testMinimalDraftWithoutRecipientsOrAttachments(): void
    {
        $draft = new MessageBuilder(subject: 'Subject Only');

        $binary = MessageWriter::write($draft);
        $message = MessageParser::parse($binary);

        $this->assertSame('Subject Only', $message->content->subject);
        $this->assertNotInstanceOf(DateTimeImmutable::class, $message->content->date);
        $this->assertNull($message->content->senderName);
        $this->assertNull($message->content->body);
        $this->assertSame('', $message->displayTo());
        $this->assertCount(0, $message->recipients);
        $this->assertCount(0, $message->attachments);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];
        $propertyEntry = $compound->directory->get('__properties_version1.0', $root->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $propertyEntry);

        $properties = $this->readPropertyMap($compound, $root, 32);

        $messageFlags = $this->propertyData($properties, '0E07');
        $clientSubmitTime = $this->propertyData($properties, '0E08');
        $locale = $this->propertyData($properties, '3FF1');
        $hasAttachments = $this->propertyData($properties, '0E1B');
        $iconIndex = $this->propertyData($properties, '1080');

        $this->assertSame(0x00000006, $messageFlags['flags']);
        $this->assertSame(0x0000000A, $messageFlags['value']);
        $this->assertSame(0x00000006, $clientSubmitTime['flags']);
        $this->assertGreaterThan(0, $clientSubmitTime['value']);
        $this->assertSame(1033, $locale['value']);
        $this->assertSame(0x00000006, $hasAttachments['flags']);
        $this->assertSame(0, $hasAttachments['value']);
        $this->assertSame(0x00000006, $iconIndex['flags']);
        $this->assertSame(0x00000103, $iconIndex['value']);
    }

    public function testHtmlBodyUsesBinaryHtmlStreamPerSpec(): void
    {
        $draft = new MessageBuilder(
            subject: 'HTML',
            bodyHtml: '<p>Hello world!</p>'
        );

        $binary = MessageWriter::write($draft);
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];

        $htmlEntry = $compound->directory->get('__substg1.0_10130102', $root->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $htmlEntry);
        $this->assertSame('<p>Hello world!</p>', $compound->readStreamToString($htmlEntry));

        $unicodeEntry = $compound->directory->get('__substg1.0_1013001F', $root->childId, false);
        $this->assertNotInstanceOf(DirectoryEntry::class, $unicodeEntry);
    }

    public function testLargeAttachmentUsesRegularFatSectors(): void
    {
        $largeContent = str_repeat('A', 6000);

        $draft = new MessageBuilder(subject: 'Large Attachment');
        $draft->attach(new Attachment(
            fileName: 'large.bin',
            mimeType: 'application/octet-stream',
            displayName: 'large.bin',
            content: $largeContent,
            method: AttachmentMethod::ByValue,
        ));

        $binary = MessageWriter::write($draft);
        $message = MessageParser::parse($binary);

        $this->assertCount(1, $message->attachments);
        $this->assertSame($largeContent, $message->attachments[0]->data());

        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];
        $attachStorage = $compound->directory->get('__attach_version1.0_#00000000', $root->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $attachStorage);

        $contentStream = $compound->directory->get('__substg1.0_37010102', $attachStorage->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $contentStream);
        $this->assertTrue($contentStream->streamSize->isGreaterThan(4096));
        $this->assertLessThan(0xFFFFFFFE, $contentStream->startingSectorLocation);
    }

    public function testAttachmentStorageContainsOutlookRequiredFixedProperties(): void
    {
        $content = 'attachment-body';
        $draft = new MessageBuilder(subject: 'Attachment Props');
        $draft->attach(new Attachment(
            extension: '.txt',
            fileName: 'doc.txt',
            mimeType: 'text/plain',
            displayName: 'Doc',
            content: $content,
            method: AttachmentMethod::ByValue,
        ));

        $binary = MessageWriter::write($draft);
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];
        $attachStorage = $compound->directory->get('__attach_version1.0_#00000000', $root->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $attachStorage);

        $properties = $this->readPropertyMap($compound, $attachStorage, 8);

        $this->assertSame(1, $this->propertyData($properties, '3705')['value']);
        $this->assertSame(0, $this->propertyData($properties, '0E21')['value']);
        $this->assertSame(strlen($content), $this->propertyData($properties, '0E20')['value']);
        $this->assertSame(7, $this->propertyData($properties, '0FFE')['value']);
        $this->assertSame(0xFFFFFFFF, $this->propertyData($properties, '370B')['value']);

        foreach (['0FF6', '0FF9', '3701', '3704', '3707', '3703', '370E'] as $propertyId) {
            $suffix = in_array($propertyId, ['0FF6', '0FF9', '3701'], true) ? '0102' : '001F';
            $entry = $compound->directory->get(sprintf('__substg1.0_%s%s', $propertyId, $suffix), $attachStorage->childId, false);
            $this->assertInstanceOf(DirectoryEntry::class, $entry);
        }
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

        $draft->attach(Attachment::fromData('AAA', 'a.txt'));
        $draft->attach(Attachment::fromData('BBB', 'b.txt'));

        $binary = MessageWriter::write($draft);
        $message = MessageParser::parse($binary);

        $this->assertSame('bob@example.com;bliz48rus@gmail.com', $message->displayTo());
        $this->assertCount(2, $message->recipients);
        $this->assertSame('Bob', $message->recipients[0]->name);
        $this->assertSame('Alice', $message->recipients[1]->name);

        $this->assertCount(2, $message->attachments);
        $this->assertSame('AAA', $message->attachments[0]->data());
        $this->assertSame('BBB', $message->attachments[1]->data());
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

    public function testRootOmitsStoreGeneratedIdentityProperties(): void
    {
        $binary = MessageWriter::write(new MessageBuilder(subject: 'Identity'));
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];

        foreach (['__substg1.0_0FF60102', '__substg1.0_0FFF0102'] as $streamName) {
            $this->assertNotInstanceOf(
                DirectoryEntry::class,
                $compound->directory->get($streamName, $root->childId, false),
            );
        }
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
        $draft->attach(Attachment::fromData('1', 'one.txt'));

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
        $this->assertSame("I\0P\0M\0.\0N\0o\0t\0e\0", $messageClass);
    }

    public function testRecipientOneOffEntryIdAndSearchKeyFollowSpec(): void
    {
        $draft = MessageBuilder::make()
            ->from('Sender', 'sender@example.com')
            ->to('Test Recipient', 'recipient@example.com')
            ->subject('Keys');

        $binary = MessageWriter::write($draft);
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];
        $recipientStorage = $compound->directory->get('__recip_version1.0_#00000000', $root->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $recipientStorage);

        $entryId = $compound->directory->get('__substg1.0_0FFF0102', $recipientStorage->childId, false);
        $recordKey = $compound->directory->get('__substg1.0_0FF90102', $recipientStorage->childId, false);
        $searchKey = $compound->directory->get('__substg1.0_300B0102', $recipientStorage->childId, false);

        $this->assertInstanceOf(DirectoryEntry::class, $entryId);
        $this->assertInstanceOf(DirectoryEntry::class, $recordKey);
        $this->assertInstanceOf(DirectoryEntry::class, $searchKey);

        $entryIdBinary = $compound->readStreamToString($entryId);
        $recordKeyBinary = $compound->readStreamToString($recordKey);
        $searchKeyBinary = $compound->readStreamToString($searchKey);

        $this->assertSame($entryIdBinary, $recordKeyBinary);
        $this->assertSame("\x00\x00\x00\x00\x81\x2B\x1F\xA4\xBE\xA3\x10\x19\x9D\x6E\x00\xDD\x01\x0F\x54\x02\x00\x00\x01\x80", substr($entryIdBinary, 0, 24));
        $this->assertSame("SMTP:RECIPIENT@EXAMPLE.COM\0", $searchKeyBinary);
    }

    public function testRecipientRowIdsAreUniquePerStorage(): void
    {
        $draft = MessageBuilder::make(subject: 'Row IDs');
        $draft->to('Alice', 'alice@example.com');
        $draft->cc('Bob', 'bob@example.com');

        $binary = MessageWriter::write($draft);
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];

        foreach ([0, 1] as $index) {
            $recipientStorage = $compound->directory->get(sprintf('__recip_version1.0_#%08X', $index), $root->childId, false);
            $this->assertInstanceOf(DirectoryEntry::class, $recipientStorage);
            $propertyEntry = $compound->directory->get('__properties_version1.0', $recipientStorage->childId, false);
            $this->assertInstanceOf(DirectoryEntry::class, $propertyEntry);
            $propertyStream = $compound->readStreamToString($propertyEntry);
            $buffer = new BinaryBuffer($propertyStream);

            $rowId = null;
            for ($offset = 8; $offset + 16 <= strlen($propertyStream); $offset += 16) {
                $propertyTag = $buffer->getUint32($offset);
                if ($propertyTag === ((0x3000 << 16) | 0x0003)) {
                    $rowId = $buffer->getUint32($offset + 8);
                    break;
                }
            }

            $this->assertSame($index, $rowId);
        }
    }

    public function testRootAndRecipientUnicodeStreamLengthsMatchDeclaredSizes(): void
    {
        $draft = MessageBuilder::make()
            ->from('Sender Name', 'sender@example.com')
            ->to('Test Recipient', 'recipient@example.com')
            ->subject('Spec Lengths')
            ->text('Body text');

        $binary = MessageWriter::write($draft);
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];

        $rootProperties = $this->readPropertyMap($compound, $root, 32);
        foreach (['001A', '0037', '003D', '0050', '0070', '0C1A', '0C1E', '0C1F', '0E02', '0E03', '0E04', '0E1D', '1000', '4022', '4023', '4038'] as $propertyId) {
            $this->assertUnicodeStreamLengthMatchesDeclaration($compound, $root, $rootProperties, $propertyId);
        }

        $recipientStorage = $compound->directory->get('__recip_version1.0_#00000000', $root->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $recipientStorage);
        $recipientProperties = $this->readPropertyMap($compound, $recipientStorage, 8);
        foreach (['3001', '3002', '3003'] as $propertyId) {
            $this->assertUnicodeStreamLengthMatchesDeclaration($compound, $recipientStorage, $recipientProperties, $propertyId);
        }
    }

    public function testRootSearchKeyIsWrittenAsBinaryStream(): void
    {
        $binary = MessageWriter::write(new MessageBuilder(subject: 'Search Key'));
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];
        $properties = $this->readPropertyMap($compound, $root, 32);

        $searchKeyProperty = $this->propertyData($properties, '300B');
        $this->assertSame(0x00000006, $searchKeyProperty['flags']);

        $entry = $compound->directory->get('__substg1.0_300B0102', $root->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $entry);
        $this->assertSame($entry->streamSize->toInt(), $searchKeyProperty['value']);
        $this->assertSame(16, $entry->streamSize->toInt());
    }

    public function testCcAndBccDisplayFieldsRoundTrip(): void
    {
        $draft = new MessageBuilder(subject: 'CC BCC Test');
        $draft->recipient(new RecipientPayload('Alice', 'alice@example.com', RecipientPayload::TO));
        $draft->recipient(new RecipientPayload('Bob', 'bob@example.com', RecipientPayload::CC));
        $draft->recipient(new RecipientPayload('Carol', 'carol@example.com', RecipientPayload::BCC));

        $binary = MessageWriter::make($draft);
        $message = MessageParser::parse($binary);

        $this->assertSame('alice@example.com', $message->displayTo());
        $this->assertSame('bob@example.com', $message->displayCc());
        $this->assertSame('carol@example.com', $message->displayBcc());
        $this->assertSame('alice@example.com', $message->to()->sole()->email());
        $this->assertSame('bob@example.com', $message->cc()->sole()->email());
        $this->assertSame('carol@example.com', $message->bcc()->sole()->email());
    }

    public function testDisplayToUsesNameWhenNoEmail(): void
    {
        $draft = new MessageBuilder(subject: 'Name-Only Recipient');
        $draft->recipient(new RecipientPayload('No Email Person', null, RecipientPayload::TO));

        $binary = MessageWriter::make($draft);
        $message = MessageParser::parse($binary);

        $this->assertSame('No Email Person', $message->displayTo());
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
        $outer->attach(Attachment::fromMessage(Message::from($inner->toBinary()), 'forwarded.msg'));

        $binary = MessageWriter::make($outer);
        $message = MessageParser::parse($binary);

        $this->assertSame('Outer Message', $message->content->subject);
        $this->assertCount(1, $message->attachments);

        $attachment = $message->attachments[0];
        $this->assertSame('forwarded.msg', $attachment->displayName());
        $this->assertInstanceOf(Message::class, $attachment->message());
        $this->assertSame('Inner Message', $attachment->message()->content->subject);
        $this->assertSame('Inner Sender', $attachment->message()->content->senderName);
        $this->assertSame('Inner body text', $attachment->message()->content->body);
        $this->assertNotInstanceOf(DateTimeImmutable::class, $attachment->message()->content->date);
    }

    public function testDifatOverflowLargeFile(): void
    {
        // >~7 MB of data forces DIFAT extension sectors (more than 109 FAT sectors needed).
        $largeContent = str_repeat('X', 8 * 1024 * 1024);

        $draft = new MessageBuilder(subject: 'DIFAT Test');
        $draft->attach(new Attachment(
            fileName: 'huge.bin',
            displayName: 'huge.bin',
            content: $largeContent,
            method: AttachmentMethod::ByValue,
        ));

        $binary = MessageWriter::make($draft);
        $message = MessageParser::parse($binary);
        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));

        $this->assertSame('DIFAT Test', $message->content->subject);
        $this->assertCount(1, $message->attachments);
        $this->assertSame($largeContent, $message->attachments[0]->data());
        $this->assertGreaterThan(109, $compound->header->numberOfFatSectors);
        $this->assertGreaterThan(0, $compound->header->numberOfDifatSectors);
        $this->assertNotSame(0xFFFFFFFE, $compound->header->firstDifatSectorLocation);
    }

    public function testEmbeddedMsgWithExtensionAndMimeType(): void
    {
        // Covers MessageWriter::buildEmbeddedMsgAttachment lines for extension and mimeType
        $inner = new MessageBuilder(subject: 'Inner');

        $attachment = new Attachment(
            extension: '.msg',
            fileName: 'nested.msg',
            mimeType: 'message/rfc822',
            language: 'ru',
            displayName: 'Nested Message',
            embedded: Message::from($inner->toBinary()),
            contentId: 'nested-message',
            inline: true,
            rawProperties: [new RawProperty('6901', 0x0003, 73)],
            method: AttachmentMethod::EmbeddedMessage,
        );

        $outer = new MessageBuilder(subject: 'Outer');
        $outer->attach($attachment);

        $binary = MessageWriter::make($outer);
        $message = MessageParser::parse($binary);

        $this->assertCount(1, $message->attachments);
        $this->assertSame('.msg', $message->attachments[0]->extension());
        $this->assertSame('message/rfc822', $message->attachments[0]->mime());
        $this->assertSame('ru', $message->attachments[0]->language());
        $this->assertSame('nested-message', $message->attachments[0]->contentId());
        $this->assertTrue($message->attachments[0]->isInline());
        $this->assertSame(73, $message->attachments[0]->rawProperties()[0]->value);
        $this->assertInstanceOf(Message::class, $message->attachments[0]->message());
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
        $this->assertArrayHasKey('difat', $decoded);
        $this->assertArrayHasKey('fat', $decoded);
        $this->assertArrayHasKey('miniFat', $decoded);
        $this->assertArrayHasKey('directory', $decoded);
    }

    public function testReadStreamToStringThrowsWhenExceedsMaxBytes(): void
    {
        $content = str_repeat('X', 100);
        $draft = new MessageBuilder(subject: 'Limit Test');
        $draft->attach(Attachment::fromData($content, 'big.txt'));

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

    public function testLargeAttachmentGenerationFitsA128MegabyteMemoryLimit(): void
    {
        $script = <<<'PHP'
require $argv[1];

use Cosmira\OutlookMessage\Message;

$payload = str_repeat('A', 32 * 1024 * 1024);
$binary = Message::make('Large attachment')
    ->attachData($payload, 'large.bin', 'application/octet-stream')
    ->toBinary();

echo json_encode([
    'size' => strlen($binary),
    'peak' => memory_get_peak_usage(true),
], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            '-r',
            $script,
            dirname(__DIR__, 2).'/vendor/autoload.php',
        ]);
        $process->setTimeout(30);
        $process->mustRun();

        /** @var array{size: int, peak: int} $result */
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(32 * 1024 * 1024, $result['size']);
        $this->assertLessThanOrEqual(128 * 1024 * 1024, $result['peak']);
    }

    public function testFileAttachmentSaveUsesBoundedMemory(): void
    {
        $process = new Process([
            PHP_BINARY,
            '-d',
            'memory_limit=64M',
            '-r',
            <<<'PHP'
require $argv[1];

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Message;

$attachment = $argv[2];
$message = $argv[3];
$handle = fopen($attachment, 'w+b');
ftruncate($handle, 64 * 1024 * 1024);
fseek($handle, (64 * 1024 * 1024) - 4);
fwrite($handle, 'TAIL');
fclose($handle);

Message::make('Streaming attachment')
    ->attach(Attachment::fromPath($attachment))
    ->save($message);

$parsed = Message::fromPath($message);
$parsed->toBuilder()->subject('Rewritten streaming attachment')->save($argv[5]);
$parsed = Message::fromPath($argv[5]);
$copy = fopen($argv[4], 'w+b');
$parsed->attachments[0]->copyTo($copy);
fclose($copy);

echo json_encode([
    'attachmentSize' => $parsed->attachments[0]->size(),
    'messageSize' => filesize($message),
    'peak' => memory_get_peak_usage(true),
    'subject' => $parsed->subject(),
], JSON_THROW_ON_ERROR);
PHP,
            dirname(__DIR__, 2).'/vendor/autoload.php',
            $attachment = sys_get_temp_dir().'/outlook-msg-streaming-attachment-'.bin2hex(random_bytes(8)),
            $message = sys_get_temp_dir().'/outlook-msg-streaming-message-'.bin2hex(random_bytes(8)).'.msg',
            $copy = sys_get_temp_dir().'/outlook-msg-streaming-copy-'.bin2hex(random_bytes(8)),
            $rewritten = sys_get_temp_dir().'/outlook-msg-streaming-rewritten-'.bin2hex(random_bytes(8)).'.msg',
        ]);
        $process->setTimeout(120);

        try {
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($result);

            $this->assertSame(64 * 1024 * 1024, $result['attachmentSize']);
            $this->assertGreaterThan(64 * 1024 * 1024, $result['messageSize']);
            $this->assertLessThanOrEqual(64 * 1024 * 1024, $result['peak']);
            $this->assertSame('Rewritten streaming attachment', $result['subject']);
            $this->assertSame(hash_file('sha256', $attachment), hash_file('sha256', $copy));
        } finally {
            @unlink($attachment);
            @unlink($message);
            @unlink($copy);
            @unlink($rewritten);
        }
    }

    /**
     * @return array<array-key, array{flags:int, value:int}>
     */
    private function readPropertyMap(CompoundFile $compound, DirectoryEntry $storage, int $headerSize): array
    {
        $propertyEntry = $compound->directory->get('__properties_version1.0', $storage->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $propertyEntry);
        $propertyStream = $compound->readStreamToString($propertyEntry);
        $buffer = new BinaryBuffer($propertyStream);
        $properties = [];

        for ($offset = $headerSize; $offset + 16 <= strlen($propertyStream); $offset += 16) {
            $propertyTag = $buffer->getUint32($offset);
            $properties[sprintf('%04X', $propertyTag >> 16)] = [
                'flags' => $buffer->getUint32($offset + 4),
                'value' => $buffer->getUint32($offset + 8),
            ];
        }

        return $properties;
    }

    /**
     * @param array<array-key, array{flags:int, value:int}> $properties
     *
     * @return array{flags:int, value:int}
     */
    private function propertyData(array $properties, string $propertyId): array
    {
        foreach ($properties as $key => $property) {
            if (strtoupper((string) $key) === strtoupper($propertyId)) {
                return $property;
            }
        }

        self::fail(sprintf('Missing property %s', $propertyId));
    }

    /**
     * @param array<array-key, array{flags:int, value:int}> $properties
     */
    private function assertUnicodeStreamLengthMatchesDeclaration(
        CompoundFile $compound,
        DirectoryEntry $storage,
        array $properties,
        string $propertyId,
    ): void {
        $entry = $compound->directory->get(sprintf('__substg1.0_%s001F', strtoupper($propertyId)), $storage->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $entry);
        $this->assertSame($entry->streamSize->toInt() + 2, $this->propertyData($properties, $propertyId)['value']);
    }
}
