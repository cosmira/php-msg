<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\CompoundFile\Directory\ObjectType;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Recipient;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class AttachmentMutationCorpusTest extends TestCase
{
    private const REPLACEMENT_DATA = "Replacement attachment\0with binary data\xFF";

    /**
     * @var list<string>
     */
    private const CORRUPTED_FIXTURES = [
        'apache-poi/clusterfuzz-testcase-minimized-POIHSMFFuzzer-4735011465854976.msg',
        'apache-poi/clusterfuzz-testcase-minimized-POIHSMFFuzzer-5336473854148608.msg',
        'apache-poi/poifs/unknown_properties.msg',
        'msg-parser-rs/bad_outlook.msg',
    ];

    public function testMutationCorpusContainsEveryKnownValidFixture(): void
    {
        $this->assertCount(179, self::fixturePaths());
    }

    #[DataProvider('validFixtureProvider')]
    public function testFlushesAttachmentsAndWritesOneOutlookMsgAttachment(string $path): void
    {
        $source = Message::fromPath($path);
        $before = $this->messageSnapshot($source);

        $binary = $source
            ->toBuilder()
            ->flushAttachment()
            ->attachData(self::REPLACEMENT_DATA, 'replacement.bin', 'application/octet-stream')
            ->toBinary();

        $edited = Message::from($binary);
        $this->assertSame($before, $this->messageSnapshot($edited), $path);
        $this->assertCount(1, $edited->attachments, $path);

        $attachment = $edited->attachments[0];
        $this->assertSame('replacement.bin', $attachment->name(), $path);
        $this->assertSame('application/octet-stream', $attachment->mime(), $path);
        $this->assertSame(self::REPLACEMENT_DATA, $attachment->data(), $path);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];
        $attachmentStorages = array_values(array_filter(
            $this->children($compound, $root),
            static fn (DirectoryEntry $entry): bool => $entry->objectType === ObjectType::Storage
                && str_starts_with($entry->entryName, '__attach_version1.0_#'),
        ));

        $this->assertCount(1, $attachmentStorages, $path);
        $this->assertSame('__attach_version1.0_#00000000', $attachmentStorages[0]->entryName, $path);

        $propertyEntry = $compound->directory->get('__properties_version1.0', $root->childId, false);
        $this->assertInstanceOf(DirectoryEntry::class, $propertyEntry, $path);
        $propertyStream = new BinaryBuffer($compound->readStreamToString($propertyEntry));
        $this->assertSame(1, $propertyStream->getUint32(20), $path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validFixtureProvider(): iterable
    {
        foreach (self::fixturePaths() as $relative => $path) {
            yield $relative => [$path];
        }
    }

    /**
     * @return array<string, string>
     */
    private static function fixturePaths(): array
    {
        $root = dirname(__DIR__).'/Fixtures';
        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || strtolower($file->getExtension()) !== 'msg') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (in_array($relative, self::CORRUPTED_FIXTURES, true)) {
                continue;
            }

            $paths[$relative] = $file->getPathname();
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageSnapshot(Message $message): array
    {
        return [
            'date'                    => $this->date($message->date()),
            'receivedAt'              => $this->date($message->receivedAt()),
            'subject'                 => $message->subject(),
            'senderName'              => $message->senderName(),
            'senderEmail'             => $message->senderEmail(),
            'actualSenderName'        => $message->actualSenderName(),
            'actualSenderEmail'       => $message->actualSenderEmail(),
            'representingName'        => $message->representingName(),
            'representingEmail'       => $message->representingEmail(),
            'body'                    => $message->body(),
            'bodyHtml'                => $message->bodyHtml(),
            'bodyRtf'                 => $message->bodyRtf(),
            'headers'                 => $message->headers(),
            'importance'              => $message->importance()?->value,
            'priority'                => $message->priority()?->value,
            'draft'                   => $message->isDraft(),
            'readReceiptRequested'    => $message->readReceiptRequested(),
            'iconIndex'               => $message->iconIndex(),
            'editorFormat'            => $message->editorFormat()?->value,
            'internetMessageId'       => $message->internetMessageId(),
            'internetReferences'      => $message->internetReferences(),
            'inReplyToId'             => $message->inReplyToId(),
            'messageClass'            => $message->messageClass(),
            'conversationTopic'       => $message->conversationTopic(),
            'messageSubmissionId'     => $message->messageSubmissionId(),
            'codepage'                => $message->content->codepage,
            'messageLocaleId'         => $message->content->messageLocaleId,
            'recipients'              => array_map($this->recipientSnapshot(...), $message->recipients),
            'rawProperties'           => array_map($this->rawPropertySnapshot(...), $message->rawProperties),
            'preservedNameIdStreams'  => $message->nameIdStreams,
        ];
    }

    /**
     * @return array<mixed>
     */
    private function recipientSnapshot(Recipient $recipient): array
    {
        return [
            $recipient->name(),
            $recipient->email(),
            $recipient->type(),
            array_map($this->rawPropertySnapshot(...), $recipient->rawProperties),
        ];
    }

    /**
     * @return array{string, int, mixed, int}
     */
    private function rawPropertySnapshot(RawProperty $property): array
    {
        return [$property->id, $property->typeId, $this->rawValue($property->value), $property->flags];
    }

    private function rawValue(mixed $value): mixed
    {
        if ($value instanceof BigInteger) {
            return (string) $value;
        }

        if (is_array($value)) {
            return array_map($this->rawValue(...), $value);
        }

        return $value;
    }

    private function date(?DateTimeInterface $date): ?string
    {
        return $date?->format('U.uP');
    }

    /**
     * @return list<DirectoryEntry>
     */
    private function children(CompoundFile $file, DirectoryEntry $parent): array
    {
        $entries = [];
        $visited = [];
        $this->walkSiblingTree($file, $parent->childId, $visited, $entries);

        return $entries;
    }

    /**
     * @param array<int, true>     $visited
     * @param list<DirectoryEntry> $entries
     */
    private function walkSiblingTree(
        CompoundFile $file,
        int $entryId,
        array &$visited,
        array &$entries,
    ): void {
        if ($entryId < 0 || $entryId >= 0xFFFFFFFE || isset($visited[$entryId])) {
            return;
        }

        $entry = $file->directory->entries[$entryId] ?? null;
        if (! $entry instanceof DirectoryEntry) {
            return;
        }

        $visited[$entryId] = true;
        $this->walkSiblingTree($file, $entry->leftSiblingId, $visited, $entries);
        $entries[] = $entry;
        $this->walkSiblingTree($file, $entry->rightSiblingId, $visited, $entries);
    }
}
