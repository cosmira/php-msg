<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\AttachmentMethod;
use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Writer\CompoundBuilder;
use Cosmira\OutlookMessage\Writer\CompoundStorageMerger;
use Cosmira\OutlookMessage\Writer\MessageBuilderFingerprint;
use PHPUnit\Framework\TestCase;

final class OpaqueStorageRoundTripTest extends TestCase
{
    public function testUnchangedParsedMessageIsByteIdentical(): void
    {
        $source = new CompoundBuilder();
        $root = $source->rootIndex();
        $source->addStream('__properties_version1.0', str_repeat("\0", 32), $root);
        $source->addStream("\x05SummaryInformation", 'outlook-only-data', $root);

        $binary = $source->build();

        $this->assertSame($binary, Message::parse($binary)->toBinary());
    }

    public function testUnknownStreamsAndNestedStoragesSurviveRoundTrip(): void
    {
        $source = new CompoundBuilder();
        $root = $source->rootIndex();
        $source->addStream('__properties_version1.0', str_repeat("\0", 32), $root);
        $source->addStream("\x05SummaryInformation", 'summary-data', $root);

        $nameId = $source->addStorage('__nameid_version1.0', $root);
        $source->addStream('__substg1.0_00020102', '', $nameId);
        $source->addStream('__substg1.0_00030102', '', $nameId);
        $source->addStream('__substg1.0_00040102', '', $nameId);
        $source->addStream('__substg1.0_10030102', 'named-property-data', $nameId);

        $dataStore = $source->addStorage('MsoDataStore', $root);
        $item = $source->addStorage('OpaqueItem', $dataStore);
        $source->addStream('Item', 'opaque-item-data', $item);
        $source->addStream('Properties', 'opaque-properties', $item);

        $roundTripped = Message::parse($source->build())
            ->toBuilder()
            ->subject('Changed')
            ->toBinary();
        $compound = CompoundFile::fromBinary(new BinaryBuffer($roundTripped));
        $rootEntry = $compound->directory->entries[0];

        $summary = $this->child($compound, $rootEntry, "\x05SummaryInformation");
        $this->assertSame('summary-data', $compound->readStreamToString($summary));

        $nameIdEntry = $this->child($compound, $rootEntry, '__nameid_version1.0');
        $namedProperty = $this->child($compound, $nameIdEntry, '__substg1.0_10030102');
        $this->assertSame('named-property-data', $compound->readStreamToString($namedProperty));

        $dataStoreEntry = $this->child($compound, $rootEntry, 'MsoDataStore');
        $itemEntry = $this->child($compound, $dataStoreEntry, 'OpaqueItem');
        $itemData = $this->child($compound, $itemEntry, 'Item');
        $properties = $this->child($compound, $itemEntry, 'Properties');
        $this->assertSame('opaque-item-data', $compound->readStreamToString($itemData));
        $this->assertSame('opaque-properties', $compound->readStreamToString($properties));
        $this->assertSame('Changed', Message::parse($roundTripped)->subject());
    }

    public function testStorageMergerSkipsAStorageCollidingWithAnExistingStream(): void
    {
        $source = new CompoundBuilder();
        $source->addStorage('Collision', $source->rootIndex());

        $target = new CompoundBuilder();
        $target->addStream('Collision', 'stream-wins', $target->rootIndex());
        CompoundStorageMerger::mergeMissing($target, $source->build());

        $compound = CompoundFile::fromBinary(new BinaryBuffer($target->build()));
        $root = $compound->directory->entries[0];
        $collision = $this->child($compound, $root, 'Collision');
        $this->assertSame('stream-wins', $compound->readStreamToString($collision));
    }

    public function testStorageMergerHandlesMissingAndInvalidDirectoryRoots(): void
    {
        $source = new CompoundBuilder();
        $binary = $source->build();
        $withoutDirectory = substr_replace($binary, pack('V', 0xFFFFFFFE), 48, 4);

        $target = new CompoundBuilder();
        CompoundStorageMerger::mergeMissing($target, $withoutDirectory);
        $this->assertSame(0, $target->rootIndex());

        $invalidChild = substr_replace($binary, pack('V', 100), 512 + 76, 4);
        CompoundStorageMerger::mergeMissing(new CompoundBuilder(), $invalidChild);
        $this->addToAssertionCount(1);
    }

    public function testEmbeddedMessageParticipatesInAnUnchangedFingerprint(): void
    {
        $inner = Message::from(Message::make('Inner')->toBinary());
        $binary = Message::make('Outer')
            ->attach(Attachment::fromMessage($inner))
            ->toBinary();

        $this->assertSame($binary, Message::parse($binary)->toBinary());
    }

    public function testFingerprintHandlesEmbeddedAndPayloadlessAttachments(): void
    {
        $inner = Message::from(Message::make('Inner')->toBinary());
        $embedded = Message::make('Outer')->attach(
            Attachment::fromMessage($inner),
        );
        $payloadless = Message::make('Outer')->attach(
            new Attachment(method: AttachmentMethod::None),
        );

        $this->assertNotSame('', MessageBuilderFingerprint::make($embedded));
        $this->assertNotSame('', MessageBuilderFingerprint::make($payloadless));
    }

    public function testCanonicalPropertyStreamWinsOverPreservedSourceStream(): void
    {
        $source = new CompoundBuilder();
        $root = $source->rootIndex();
        $subject = mb_convert_encoding("Old\0", 'UTF-16LE', 'UTF-8');
        $property = pack('V', (0x0037 << 16) | 0x001F)
            .pack('V', 0x00000006)
            .pack('V', strlen($subject))
            .pack('V', 0);
        $source->addStream('__properties_version1.0', str_repeat("\0", 32).$property, $root);
        $source->addStream('__substg1.0_0037001F', $subject, $root);

        $binary = Message::parse($source->build())
            ->toBuilder()
            ->subject('Changed')
            ->toBinary();

        $this->assertSame('Changed', Message::parse($binary)->subject());
    }

    public function testFlushedSourceAttachmentStoragesAreNotRestored(): void
    {
        $source = Message::make('Replace attachments')
            ->attachData('old-first', 'first.txt', 'text/plain')
            ->attachData('old-second', 'second.txt', 'text/plain')
            ->toBinary();

        $binary = Message::from($source)
            ->toBuilder()
            ->flushAttachment()
            ->attachData('replacement', 'replacement.txt', 'text/plain')
            ->toBinary();

        $message = Message::from($binary);
        $this->assertCount(1, $message->attachments);
        $this->assertSame('replacement.txt', $message->attachments[0]->name());
        $this->assertSame('replacement', $message->attachments[0]->data());

        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $attachmentStorages = array_values(array_filter(
            $compound->directory->entries,
            static fn (DirectoryEntry $entry): bool => str_starts_with(
                $entry->entryName,
                '__attach_version1.0_#',
            ),
        ));

        $this->assertCount(1, $attachmentStorages);
        $this->assertSame('__attach_version1.0_#00000000', $attachmentStorages[0]->entryName);
    }

    public function testChangedAttachmentStorageDoesNotInheritOpaqueSourceStreams(): void
    {
        $source = new CompoundBuilder();
        $sourceAttachment = $source->addStorage('__attach_version1.0_#00000000', $source->rootIndex());
        $source->addStream('OpaqueAttachmentData', 'old-data', $sourceAttachment);

        $target = new CompoundBuilder();
        $targetAttachment = $target->addStorage('__attach_version1.0_#00000000', $target->rootIndex());
        $target->addStream('ReplacementData', 'new-data', $targetAttachment);

        CompoundStorageMerger::mergeMissing($target, $source->build());
        $compound = CompoundFile::fromBinary(new BinaryBuffer($target->build()));
        $root = $compound->directory->entries[0];
        $attachment = $this->child($compound, $root, '__attach_version1.0_#00000000');

        $this->assertNotInstanceOf(DirectoryEntry::class, $compound->directory->get('OpaqueAttachmentData', $attachment->childId, false));
        $replacement = $this->child($compound, $attachment, 'ReplacementData');
        $this->assertSame('new-data', $compound->readStreamToString($replacement));
    }

    public function testUnchangedAttachmentStorageRetainsOpaqueSourceStreams(): void
    {
        $source = new CompoundBuilder();
        $sourceAttachment = $source->addStorage('__attach_version1.0_#00000000', $source->rootIndex());
        $source->addStream('OpaqueAttachmentData', 'preserved-data', $sourceAttachment);

        $target = new CompoundBuilder();
        $target->addStorage('__attach_version1.0_#00000000', $target->rootIndex());

        CompoundStorageMerger::mergeMissing($target, $source->build(), [0]);
        $compound = CompoundFile::fromBinary(new BinaryBuffer($target->build()));
        $root = $compound->directory->entries[0];
        $attachment = $this->child($compound, $root, '__attach_version1.0_#00000000');
        $opaque = $this->child($compound, $attachment, 'OpaqueAttachmentData');

        $this->assertSame('preserved-data', $compound->readStreamToString($opaque));
    }

    private function child(CompoundFile $file, DirectoryEntry $parent, string $name): DirectoryEntry
    {
        $entry = $file->directory->get($name, $parent->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $entry);

        return $entry;
    }
}
