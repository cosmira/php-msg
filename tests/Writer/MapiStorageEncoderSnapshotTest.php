<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\AttachmentMethod;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Writer\AttachmentStorageMetadata;
use Cosmira\OutlookMessage\Writer\MapiStorageEncoder;
use Cosmira\OutlookMessage\Writer\MessageBuilder;
use Cosmira\OutlookMessage\Writer\RecipientPayload;
use Cosmira\OutlookMessage\Writer\StorageStreams;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

final class MapiStorageEncoderSnapshotTest extends TestCase
{
    /** Ensure regular encoding rejects attachment methods it cannot represent. */
    public function testRegularAttachmentEncoderRejectsNonByValueAttachments(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Regular attachments require the by-value attachment method.');

        MapiStorageEncoder::forAttachment(new Attachment(method: AttachmentMethod::None));
    }

    /** Ensure embedded encoding requires an actual nested message payload. */
    public function testEmbeddedAttachmentEncoderRejectsMissingMessagePayload(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Embedded attachments require an embedded message.');

        MapiStorageEncoder::forEmbeddedAttachment(Attachment::fromData('data', 'file.bin'));
    }

    /** Ensure embedded encoding rejects a payload carrying the wrong MAPI method. */
    public function testEmbeddedAttachmentEncoderRejectsNonEmbeddedAttachmentMethod(): void
    {
        $message = Message::from((new MessageBuilder(subject: 'Inner'))->toBinary());
        $attachment = new Attachment(embedded: $message, method: AttachmentMethod::ByValue);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Embedded attachments require the embedded-message attachment method.');

        MapiStorageEncoder::forEmbeddedAttachment($attachment);
    }

    /** Ensure public encoder defaults produce the documented zero-based row numbers. */
    public function testDefaultEncoderIndexesAreZeroBased(): void
    {
        $recipient = MapiStorageEncoder::forRecipient(new RecipientPayload('Alice', 'a@example.com'));
        $attachment = MapiStorageEncoder::forAttachment(Attachment::fromData('data', 'file.bin'));
        $embedded = MapiStorageEncoder::forEmbeddedAttachment(
            Attachment::fromMessage(Message::from((new MessageBuilder(subject: 'Inner'))->toBinary())),
        );

        $this->assertSame(0, $this->fixedInteger($recipient, 8, '3000'));
        $this->assertSame(0, $this->fixedInteger($attachment, 8, '0E21'));
        $this->assertSame(0, $this->fixedInteger($embedded, 8, '0E21'));
    }

    /** Ensure Outlook subject-prefix detection is anchored and rejects unsupported forms. */
    public function testSubjectPrefixDetectionUsesTheWholePrefixGrammar(): void
    {
        $prefixed = MapiStorageEncoder::forMessage(new MessageBuilder(subject: 'RE: Topic'));
        $middle = MapiStorageEncoder::forMessage(new MessageBuilder(subject: 'Topic RE: Later'));
        $tooLong = MapiStorageEncoder::forMessage(new MessageBuilder(subject: 'LONG: Topic'));
        $numeric = MapiStorageEncoder::forMessage(new MessageBuilder(subject: 'R1: Topic'));

        $this->assertSame('RE: ', $this->unicodeStream($prefixed, '003D'));
        $this->assertSame('Topic', $this->unicodeStream($prefixed, '0070'));
        $this->assertSame('', $this->unicodeStream($middle, '003D'));
        $this->assertSame('Topic RE: Later', $this->unicodeStream($middle, '0070'));
        $this->assertSame('', $this->unicodeStream($tooLong, '003D'));
        $this->assertSame('LONG: Topic', $this->unicodeStream($tooLong, '0070'));
        $this->assertSame('', $this->unicodeStream($numeric, '003D'));
        $this->assertSame('R1: Topic', $this->unicodeStream($numeric, '0070'));
    }

    /** Ensure the message-size default does not silently add phantom sub-storage bytes. */
    public function testDefaultMessageSubStorageSizeIsZero(): void
    {
        $default = MapiStorageEncoder::forMessage(new MessageBuilder(subject: 'Size'));
        $oneByte = MapiStorageEncoder::forMessage(new MessageBuilder(subject: 'Size'), 1);

        $this->assertSame(
            $this->fixedInteger($default, 32, '0E08') + 1,
            $this->fixedInteger($oneByte, 32, '0E08'),
        );
    }

    /** Ensure a preserved compressed RTF payload is preferred over recompression. */
    public function testExistingCompressedRtfPayloadIsPreserved(): void
    {
        $compressed = 'preserved-compressed-rtf';
        $storage = MapiStorageEncoder::forMessage(new MessageBuilder(
            bodyRtf: '{\\rtf1 decoded}',
            bodyRtfCompressed: $compressed,
        ));

        $this->assertSame($compressed, $storage->streams['__substg1.0_10090102']);
    }

    /** Ensure an unknown raw property cannot prevent subsequent supported properties from encoding. */
    public function testUnknownRawPropertyDoesNotStopFollowingProperties(): void
    {
        $builder = new MessageBuilder(subject: 'Raw');
        $builder->rawProperty(new RawProperty('66FF', 0xFFFF, 'unsupported'));
        $builder->rawProperty(new RawProperty('6700', 0x0003, 42));

        $storage = MapiStorageEncoder::forMessage($builder);

        $this->assertSame(42, $this->fixedInteger($storage, 32, '6700'));
    }

    /** Ensure explicit rendering positions survive both attachment encoders. */
    public function testRenderingPositionsAreEncodedForEveryAttachmentKind(): void
    {
        $regular = Attachment::fromData('data', 'file.bin');
        $embedded = Attachment::fromMessage(Message::from((new MessageBuilder(subject: 'Inner'))->toBinary()));
        AttachmentStorageMetadata::rememberRenderingPosition($regular, 41);
        AttachmentStorageMetadata::rememberRenderingPosition($embedded, 42);

        $this->assertSame(41, $this->fixedInteger(MapiStorageEncoder::forAttachment($regular), 8, '370B'));
        $this->assertSame(42, $this->fixedInteger(MapiStorageEncoder::forEmbeddedAttachment($embedded), 8, '370B'));
    }

    public function testCanonicalStorageSnapshots(): void
    {
        $message = new MessageBuilder(
            subject: 'RE: Topic',
            senderName: 'Sender',
            senderEmail: 's@example.com',
            body: 'Body',
            bodyHtml: '<b>Body</b>',
            bodyRtf: '{\\rtf1 Body}',
            headers: 'X-Test: yes',
            date: new DateTimeImmutable('2024-01-02T03:04:05+00:00'),
        );
        $message->to('A', 'a@example.com')->attach(Attachment::fromData('x', 'x.txt'));

        $this->assertStorage(
            MapiStorageEncoder::forMessage($message, 123),
            32,
            '4ddce6a8a3ed09176046e928bf624c8e90856ea9c5fa02d4df7c944d0ada72b8',
            '7235827b24f5a8c22104a887b4bd3b804fb9a0723aa33c367000cc74bf7de286',
        );
        $this->assertStorage(
            MapiStorageEncoder::forRecipient(new RecipientPayload('Alice', 'a@example.com', 2), 7),
            8,
            'fdfcffaad4ccf158c4f0e7779155d3b35eb7ab5c5855ee16914469ac1b96ab40',
            '50b33dd7c79607ba940336af8fa2f86f2008015bb52be43cc9f8186e0433f544',
        );
        $this->assertStorage(
            MapiStorageEncoder::forAttachment(new Attachment(
                extension: '.txt', fileName: 'x.txt', mimeType: 'text/plain', language: 'en',
                displayName: 'X', content: 'abc', contentId: 'cid', inline: true,
                method: AttachmentMethod::ByValue,
            ), 3),
            8,
            'ff3b87eea18d527cdf98dd6aec7bff009969ab8ef83760d6e54eeb3eb6a5b259',
            '799777704f6e18fb4050976905246792eaf1e719370b28cc67845e25d09fd136',
        );
        $this->assertStorage(
            MapiStorageEncoder::forEmbeddedAttachment(
                Attachment::fromMessage(Message::from((new MessageBuilder(subject: 'Inner'))->toBinary()), 'inner.msg'),
                4,
            ),
            8,
            '7e9d5cf52225db74c985a98f0344b02a027aa13a74ff728b2d188103e92981ab',
            '5109da2c19e79c835f0cc8e41a2af418b90b4fd0fc40fdda8d22e37acb015392',
        );
    }

    private function assertStorage(StorageStreams $storage, int $headerSize, string $propertiesHash, string $streamsHash): void
    {
        $properties = $storage->properties;
        for ($offset = $headerSize; $offset + 16 <= strlen($properties); $offset += 16) {
            $tag = (new BinaryBuffer($properties))->getUint32($offset);
            if (($tag >> 16) === 0x3007 || ($tag >> 16) === 0x3008) {
                $properties = substr_replace($properties, str_repeat("\0", 8), $offset + 8, 8);
            }
        }

        $streams = $storage->streams;
        foreach ($streams as $name => $value) {
            $lower = strtolower($name);
            if (str_contains($lower, '0ff6') || str_contains($lower, '0ff9') || str_contains($lower, '300b')) {
                $streams[$name] = str_repeat("\0", strlen($value));
            }
        }

        ksort($streams);

        $this->assertSame($propertiesHash, hash('sha256', $properties));
        $this->assertSame($streamsHash, hash('sha256', serialize($streams)));
    }

    /** Read a fixed-width integer property from a storage property stream. */
    private function fixedInteger(StorageStreams $storage, int $headerSize, string $propertyId): int
    {
        $buffer = new BinaryBuffer($storage->properties);
        for ($offset = $headerSize; $offset + 16 <= strlen($storage->properties); $offset += 16) {
            $tag = $buffer->getUint32($offset);
            if (($tag >> 16) === hexdec($propertyId)) {
                return $buffer->getUint32($offset + 8);
            }
        }

        $this->fail(sprintf('Property %s was not encoded.', $propertyId));
    }

    /** Decode a Unicode MAPI stream by property identifier. */
    private function unicodeStream(StorageStreams $storage, string $propertyId): string
    {
        $name = sprintf('__substg1.0_%s001F', strtoupper($propertyId));
        $this->assertArrayHasKey($name, $storage->streams);

        return mb_convert_encoding($storage->streams[$name], 'UTF-8', 'UTF-16LE');
    }
}
