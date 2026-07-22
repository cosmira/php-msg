<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\MsgParser;

use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Exception\ParseException;
use Cosmira\OutlookMessage\MessageParser;
use Cosmira\OutlookMessage\Writer\CompoundBuilder;
use PHPUnit\Framework\TestCase;

final class NegativeParseTest extends TestCase
{
    public function testEmptyStringThrowsCorruptedFileException(): void
    {
        $this->expectException(CorruptedFileException::class);
        MessageParser::parse('');
    }

    public function testRandomBytesThrowsCorruptedFileException(): void
    {
        $this->expectException(CorruptedFileException::class);
        MessageParser::parse(random_bytes(512));
    }

    public function testTruncatedHeaderThrowsCorruptedFileException(): void
    {
        $this->expectException(CorruptedFileException::class);
        // CFB magic + partial header
        MessageParser::parse("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 40));
    }

    public function testWrongMagicThrowsCorruptedFileException(): void
    {
        $this->expectException(CorruptedFileException::class);
        // Valid-length header but wrong magic bytes
        MessageParser::parse(str_repeat("\xAB", 512));
    }

    public function testParseExceptionIsSubclassOfRuntimeException(): void
    {
        try {
            MessageParser::parse('not a msg file');
        } catch (\RuntimeException $runtimeException) {
            // Must be catchable as RuntimeException for backward compat
            $this->assertInstanceOf(CorruptedFileException::class, $runtimeException);

            return;
        }

        $this->fail('Expected RuntimeException');
    }

    public function testCorruptedFileExceptionExtendsParseException(): void
    {
        try {
            MessageParser::parse('nope');
        } catch (ParseException $parseException) {
            $this->assertInstanceOf(CorruptedFileException::class, $parseException);

            return;
        }

        $this->fail('Expected ParseException');
    }

    public function testMissingRootDirectoryThrowsParseException(): void
    {
        // Build a valid CFB but patch firstDirSectorLocation to 0xFFFFFFFE
        // so Directory::load produces an empty entries array.
        $builder = new CompoundBuilder();
        $binary = $builder->build();

        // firstDirSectorLocation is at offset 48–51 in the header
        $patched = substr_replace($binary, pack('V', 0xFFFFFFFE), 48, 4);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('MSG root directory is missing.');
        MessageParser::parse($patched);
    }

    public function testMissingPropertyStreamThrowsParseException(): void
    {
        // Build a CFB with a root entry but no __properties_version1.0 stream
        $builder = new CompoundBuilder();
        $binary = $builder->build();

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('MSG property stream is missing.');
        MessageParser::parse($binary);
    }

    public function testAttachmentWithoutPropertyStreamIsSkipped(): void
    {
        // Build a MSG where an attachment subfolder has no __properties_version1.0 —
        // the attachment should be silently skipped (line 121 of MessageParser).
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $propStream = str_repeat("\0", 8)
            .pack('V', 0) // nextRecipientId
            .pack('V', 1) // nextAttachmentId
            .pack('V', 0) // recipientCount
            .pack('V', 1) // attachmentCount
            .str_repeat("\0", 8);

        $builder->addStream('__properties_version1.0', $propStream, $root);
        // Attachment storage with no __properties_version1.0 inside
        $builder->addStorage('__attach_version1.0_#00000000', $root);

        $message = MessageParser::parse($builder->build());
        $this->assertCount(0, $message->attachments);
    }

    public function testRecipientWithoutPropertyStreamIsSkipped(): void
    {
        // Build a MSG where a recipient subfolder has no __properties_version1.0.
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $propStream = str_repeat("\0", 8)
            .pack('V', 1) // nextRecipientId
            .pack('V', 0) // nextAttachmentId
            .pack('V', 1) // recipientCount
            .pack('V', 0) // attachmentCount
            .str_repeat("\0", 8);

        $builder->addStream('__properties_version1.0', $propStream, $root);
        // Recipient storage with no property stream
        $builder->addStorage('__recip_version1.0_#00000000', $root);

        $message = MessageParser::parse($builder->build());
        $this->assertCount(0, $message->recipients);
    }

    public function testDeclaredChildCountsStopWhenStoragesAreMissing(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();
        $propertyStream = str_repeat("\0", 8)
            .pack('V', 1)
            .pack('V', 1)
            .pack('V', 1)
            .pack('V', 1)
            .str_repeat("\0", 8);
        $builder->addStream('__properties_version1.0', $propertyStream, $root);

        $message = MessageParser::parse($builder->build());

        $this->assertSame([], $message->recipients);
        $this->assertSame([], $message->attachments);
    }

    public function testPreEpochDateReturnsNullForContent(): void
    {
        // Build a MSG with PR_MESSAGE_DELIVERY_TIME (0x0E06, type 0x0040=FILETIME)
        // set to a value before the Unix epoch (1970-01-01).
        // FILETIME value of 100 = 10 microseconds after 1601-01-01, which is before 1970.
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $filetimeValue = 100; // 100 × 100ns = 10μs after 1601-01-01
        $low = $filetimeValue & 0xFFFFFFFF;
        $high = ($filetimeValue >> 32) & 0xFFFFFFFF;

        $propEntry = pack('V', (0x0E06 << 16) | 0x0040) // PR_MESSAGE_DELIVERY_TIME tag
            .pack('V', 0)         // flags
            .pack('V', $low)
            .pack('V', $high);

        $header = str_repeat("\0", 8)
            .pack('V', 0).pack('V', 0).pack('V', 0).pack('V', 0)
            .str_repeat("\0", 8);

        $builder->addStream('__properties_version1.0', $header.$propEntry, $root);

        $message = MessageParser::parse($builder->build());
        $this->assertNotInstanceOf(\DateTimeImmutable::class, $message->content->date);
    }
}
