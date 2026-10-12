<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Exception\ParseException;
use Cosmira\OutlookMessage\Mapi\Decoding\PropertyReader;
use Cosmira\OutlookMessage\Mapi\Decoding\ValueDecoder;
use Cosmira\OutlookMessage\Mapi\Folders;
use Cosmira\OutlookMessage\Mapi\Properties;
use Cosmira\OutlookMessage\Mapi\PropertyDefinition;
use Cosmira\OutlookMessage\Mapi\PropertyReadContext;
use Cosmira\OutlookMessage\Mapi\PropertyStreamEntry;
use Cosmira\OutlookMessage\Mapi\PropertyStreamReader;
use Cosmira\OutlookMessage\Rtf\RtfDecompressor;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Support\BinarySource;
use Cosmira\OutlookMessage\Support\MimeType;
use Cosmira\OutlookMessage\Writer\AttachmentStorageMetadata;
use Cosmira\OutlookMessage\Writer\MessageStorageMetadata;

final class MessageParser
{
    /**
     * Canonical date type used by this reader.
     */
    private const DATE_TYPE = 'date';

    /**
     * Maximum supported depth of recursively embedded messages.
     */
    private const MAX_NESTING_DEPTH = 50;

    /**
     * Bit flag on PR_ATTACH_FLAGS: attachment is rendered inline.
     */
    private const ATTACH_FLAG_RENDEREDINBODY = 0x04;

    /**
     * Named-property mapping streams retained during message parsing.
     */
    private const NAME_ID_STREAMS = [
        '__substg1.0_00020102',
        '__substg1.0_00030102',
        '__substg1.0_00040102',
    ];

    /**
     * Common Windows locale identifiers mapped to their legacy ANSI codepage.
     *
     * @var array<int, int>
     */
    private const LOCALE_CODEPAGES = [
        1025 => 1256,
        1028 => 950,
        1029 => 1250,
        1030 => 1252,
        1031 => 1252,
        1032 => 1253,
        1033 => 1252,
        1034 => 1252,
        1035 => 1252,
        1036 => 1252,
        1037 => 1255,
        1038 => 1250,
        1039 => 1252,
        1040 => 1252,
        1041 => 932,
        1042 => 949,
        1043 => 1252,
        1044 => 1252,
        1045 => 1250,
        1046 => 1252,
        1049 => 1251,
        1050 => 1250,
        1051 => 1250,
        1054 => 874,
        1055 => 1254,
        1058 => 1251,
        1060 => 1250,
        1061 => 1257,
        1062 => 1257,
        1063 => 1257,
        1065 => 1256,
        2052 => 936,
        2057 => 1252,
        2070 => 1252,
        3076 => 950,
        3079 => 1252,
        3082 => 1252,
        4100 => 936,
        5124 => 950,
    ];

    /**
     * Parse Outlook MSG binary into a message instance.
     */
    public static function parse(string $binary): Message
    {
        $message = self::parseBuffer(new BinaryBuffer($binary));
        MessageStorageMetadata::remember($message, $binary);

        return $message;
    }

    /**
     * Parse an Outlook MSG file without loading the complete file into memory.
     */
    public static function parsePath(string $path): Message
    {
        try {
            $buffer = BinaryBuffer::fromPath($path);
            $message = self::parseBuffer($buffer);
            MessageStorageMetadata::rememberSource($message, BinarySource::fromBuffer($buffer));

            return $message;
        } catch (ParseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new CorruptedFileException(
                'Unable to read message from "'.$path.'": '.$exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    /**
     * Parse an Outlook MSG message from a random-access binary buffer.
     */
    private static function parseBuffer(BinaryBuffer $buffer): Message
    {
        Properties::init();

        try {
            $file = CompoundFile::fromBinary($buffer);
        } catch (ParseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new CorruptedFileException(
                'Failed to parse Compound File: '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        $root = $file->directory->root();

        throw_unless(
            $root instanceof DirectoryEntry,
            ParseException::class,
            'MSG root directory is missing.',
        );

        return self::fromDirectory($file, $root, 0, true);
    }

    /**
     * Build a message from the selected storage and its child entries.
     */
    private static function fromDirectory(
        CompoundFile $file,
        DirectoryEntry $dir,
        int $depth = 0,
        bool $includeNameId = false,
    ): Message {
        throw_if(
            $depth > self::MAX_NESTING_DEPTH,
            ParseException::class,
            'MSG nesting depth limit exceeded.',
        );

        $propertyStream = PropertyStreamReader::forFolder($file, $dir, $includeNameId);

        throw_unless(
            $propertyStream instanceof PropertyStreamEntry,
            ParseException::class,
            'MSG property stream is missing.',
        );

        $knownIds = self::knownPropertyIds([
            Properties::$rootProperties,
            [Properties::$codepageProperty],
        ]);
        $internetCodepage = self::internetCodepage($file, $dir, $propertyStream);
        $generalCodepage = self::generalCodepage($propertyStream, $internetCodepage);

        return new Message(
            self::content($file, $dir, $propertyStream, $generalCodepage, $internetCodepage),
            self::attachments($file, $dir, $depth, $generalCodepage),
            self::recipients($file, $dir, $generalCodepage),
            self::rawProperties($file, $dir, $propertyStream, $knownIds, $generalCodepage),
            $includeNameId ? self::nameIdStreams($file, $dir) : [],
        );
    }

    /**
     * Assemble message metadata and decoded body variants from MAPI properties.
     */
    private static function content(
        CompoundFile $file,
        DirectoryEntry $dir,
        PropertyStreamEntry $entry,
        ?int $generalCodepage,
        ?int $internetCodepage,
    ): MessageContent {
        // PidTagBody in a PtypString8 stream uses the message codepage.
        // PidTagInternetCodepage describes MIME conversion and can differ
        // (for example ISO-2022-JP headers with a Shift-JIS MAPI body).
        $values = PropertyReader::extractValues(
            new PropertyReadContext(
                $file,
                $dir,
                $entry,
                $generalCodepage,
                $generalCodepage,
                $internetCodepage ?? $generalCodepage,
            ),
            Properties::$rootProperties,
        );
        $compressedRtf = ValueDecoder::stringOrNull($values['bodyRtf'] ?? null);
        $date = ValueDecoder::submissionDateOrNull($values['messageSubmissionId'] ?? null)
            ?? ValueDecoder::dateOrNull($values['clientSubmitTime'] ?? null)
            ?? ValueDecoder::dateOrNull($values[self::DATE_TYPE] ?? null);
        $actualSenderName = ValueDecoder::stringOrNull($values['senderName'] ?? null);
        $actualEmail = ValueDecoder::firstEmailOrNull(
            $values['senderSmtpEmail'] ?? null,
            $values['senderAlternateSmtpEmail'] ?? null,
            $values['senderEmail'] ?? null,
        );
        $representingName = ValueDecoder::stringOrNull($values['sentRepresentingName'] ?? null);
        $representedEmail = ValueDecoder::firstEmailOrNull(
            $values['sentRepresentingEmail'] ?? null,
        );
        $messageFlags = ValueDecoder::intOrZero($values['messageFlags'] ?? null);

        return new MessageContent(
            $date,
            ValueDecoder::stringOrNull($values['subject'] ?? null),
            $representingName ?? $actualSenderName,
            $representedEmail ?? $actualEmail,
            ValueDecoder::stringOrNull($values['body'] ?? null),
            ValueDecoder::htmlOrNull(
                $values['bodyHtml'] ?? null,
                $internetCodepage ?? $generalCodepage,
            ),
            self::decodedRtf($compressedRtf),
            ValueDecoder::stringOrNull($values['headers'] ?? null),
            bodyRtfCompressed: $compressedRtf,
            receivedAt: ValueDecoder::dateOrNull($values[self::DATE_TYPE] ?? null),
            actualSenderName: $actualSenderName,
            actualSenderEmail: $actualEmail,
            representingName: $representingName,
            representingEmail: $representedEmail,
            importance: ValueDecoder::intOrNull($values['importance'] ?? null),
            priority: ValueDecoder::intOrNull($values['priority'] ?? null),
            draft: ($messageFlags & 0x0008) !== 0,
            readReceiptRequested: ValueDecoder::intOrZero(
                $values['readReceiptRequested'] ?? null,
            ) !== 0,
            iconIndex: ValueDecoder::intOrNull($values['iconIndex'] ?? null),
            editorFormat: ValueDecoder::intOrNull($values['messageEditorFormat'] ?? null),
            internetMessageId: ValueDecoder::stringOrNull($values['internetMessageId'] ?? null),
            internetReferences: ValueDecoder::stringOrNull($values['internetReferences'] ?? null),
            inReplyToId: ValueDecoder::stringOrNull($values['inReplyToId'] ?? null),
            messageClass: ValueDecoder::stringOrNull($values['messageClass'] ?? null),
            conversationTopic: ValueDecoder::stringOrNull($values['conversationTopic'] ?? null),
            messageSubmissionId: ValueDecoder::stringOrNull($values['messageSubmissionId'] ?? null),
            codepage: $generalCodepage,
            messageLocaleId: ValueDecoder::intOrNull($values['messageLocaleId'] ?? null),
        );
    }

    /**
     * Decode a present compressed RTF body and retain absent or empty data as null.
     */
    private static function decodedRtf(?string $compressed): ?string
    {
        return $compressed === null || $compressed === ''
            ? null : RtfDecompressor::decompress($compressed);
    }

    /**
     * Read attachment storages and resolve their binary or embedded payloads.
     *
     * @return Attachment[]
     */
    private static function attachments(
        CompoundFile $file,
        DirectoryEntry $dir,
        int $depth = 0,
        ?int $parentCodepage = null,
    ): array {
        $attachments = [];

        $knownIds = self::knownPropertyIds([Properties::$attachmentProperties]);
        foreach (self::indexedStorages($file, $dir, '__attach_version1.0_#') as $directory) {
            $entry = PropertyStreamReader::forFolder($file, $directory);

            $hasProperties = $entry instanceof PropertyStreamEntry;

            if (! $hasProperties) {
                continue;
            }

            $internetCodepage = self::internetCodepage($file, $directory, $entry);
            $codepage = self::generalCodepage($entry, $internetCodepage) ?? $parentCodepage;
            $attachments[] = self::attachmentFromProperties(
                $file,
                $directory,
                $entry,
                $knownIds,
                $depth,
                $codepage,
            );
        }

        return $attachments;
    }

    /**
     * Decode one attachment storage, retaining its lazy payload and source metadata.
     *
     * @param list<string> $knownIds
     */
    private static function attachmentFromProperties(
        CompoundFile $file,
        DirectoryEntry $directory,
        PropertyStreamEntry $entry,
        array $knownIds,
        int $depth,
        ?int $codepage,
    ): Attachment {
        $values = PropertyReader::extractValues(
            new PropertyReadContext($file, $directory, $entry, $codepage),
            array_values(array_filter(
                Properties::$attachmentProperties,
                static fn (PropertyDefinition $property): bool => $property->name !== 'content',
            )),
        );

        $isInline = (ValueDecoder::intOrZero($values['attachFlags'] ?? null)
            & self::ATTACH_FLAG_RENDEREDINBODY) !== 0;
        $method = self::attachmentMethod($values['attachMethod'] ?? null);
        $embeddedDir = $values['embeddedMsgObj'] ?? null;
        $hasEmbedded = $method === AttachmentMethod::EmbeddedMessage
            && $embeddedDir instanceof DirectoryEntry;
        $embedded = $hasEmbedded
            ? self::fromDirectory($file, $embeddedDir, $depth + 1)
            : null;
        $fileName = ValueDecoder::firstStringOrNull(
            $values['fileName'] ?? null,
            $values['attachFileName'] ?? null,
        );
        $mimeType = ValueDecoder::stringOrNull($values['mimeType'] ?? null);

        if ($method === AttachmentMethod::ByValue && $fileName === null) {
            $fileName = self::smimeFileName($mimeType);
        }

        if ($method === AttachmentMethod::ByValue && $mimeType === null) {
            $mimeType = MimeType::fromFileName($fileName);
        }

        $content = self::attachmentSource($file, $directory);

        $attachment = new Attachment(
            ValueDecoder::stringOrNull($values['extension'] ?? null)
                ?? self::extensionFromFileName($fileName),
            $fileName,
            $mimeType,
            ValueDecoder::stringOrNull($values['language'] ?? null),
            ValueDecoder::stringOrNull($values['displayName'] ?? null),
            $content,
            $embedded,
            ValueDecoder::stringOrNull($values['contentId'] ?? null),
            $isInline,
            self::rawProperties($file, $directory, $entry, $knownIds, $codepage),
            $method,
        );
        self::rememberAttachmentMetadata($attachment, $values, $method);

        return $attachment;
    }

    /**
     * Retain rendering metadata and opaque source storages for round-trip writing.
     *
     * @param array<string, mixed> $values
     */
    private static function rememberAttachmentMetadata(
        Attachment $attachment,
        array $values,
        ?AttachmentMethod $method,
    ): void {
        AttachmentStorageMetadata::rememberRenderingPosition(
            $attachment,
            ValueDecoder::intOrNull($values['renderingPosition'] ?? null),
        );
        if ($method instanceof AttachmentMethod && ! in_array(
            $method,
            [AttachmentMethod::ByValue, AttachmentMethod::EmbeddedMessage],
            true,
        )) {
            AttachmentStorageMetadata::rememberOpaqueAttachment($attachment);
        }
    }

    /**
     * Retain an attachment stream as a bounded copy and incremental hash source.
     */
    private static function attachmentSource(
        CompoundFile $file,
        DirectoryEntry $directory,
    ): ?BinarySource {
        $contentEntry = $file->directory->get(
            '__substg1.0_37010102',
            $directory->childId,
            false,
        );

        return $contentEntry instanceof DirectoryEntry
            ? BinarySource::fromWriter(
                $contentEntry->streamSize->toInt(),
                static function (mixed $destination) use ($file, $contentEntry): void {
                    $file->copyStreamTo($contentEntry, $destination);
                },
                static function (string $algorithm) use ($file, $contentEntry): string {
                    $context = hash_init($algorithm);
                    $file->readStream(
                        $contentEntry,
                        static function (int $_offset, string $chunk) use ($context): void {
                            hash_update($context, $chunk);
                        },
                        1024 * 1024,
                    );

                    return hash_final($context);
                },
            )
            : null;

    }

    /**
     * Read recipient storages and normalize their addressing metadata.
     *
     * @return Recipient[]
     */
    private static function recipients(
        CompoundFile $file,
        DirectoryEntry $dir,
        ?int $parentCodepage = null,
    ): array {
        $recipients = [];

        $knownIds = self::knownPropertyIds([Properties::$recipientProperties]);
        foreach (self::indexedStorages($file, $dir, '__recip_version1.0_#') as $directory) {
            $entry = PropertyStreamReader::forFolder($file, $directory);

            $hasProperties = $entry instanceof PropertyStreamEntry;

            if (! $hasProperties) {
                continue;
            }

            $internetCodepage = self::internetCodepage($file, $directory, $entry);
            $codepage = self::generalCodepage($entry, $internetCodepage) ?? $parentCodepage;
            $values = PropertyReader::extractValues(
                new PropertyReadContext($file, $directory, $entry, $codepage),
                Properties::$recipientProperties,
            );

            if ($values === []) {
                continue;
            }

            $name = ValueDecoder::stringOrNull($values['name'] ?? null);
            $email = ValueDecoder::stringOrNull($values['email'] ?? null);

            $recipients[] = new Recipient(
                $name,
                $email ?? ValueDecoder::emailOrNull($name),
                isset($values['type']) ? ValueDecoder::intOrZero($values['type']) : null,
                self::rawProperties($file, $directory, $entry, $knownIds, $codepage),
            );
        }

        return $recipients;
    }

    /**
     * Find indexed MAPI storages without assuming that their identifiers are contiguous.
     *
     * @return list<DirectoryEntry>
     */
    private static function indexedStorages(
        CompoundFile $file,
        DirectoryEntry $parent,
        string $prefix,
    ): array {
        $storages = [];

        foreach ($file->directory->children($parent) as $entry) {
            if (! str_starts_with($entry->entryName, $prefix)) {
                continue;
            }

            $suffix = substr($entry->entryName, strlen($prefix));
            if (strlen($suffix) !== 8) {
                continue;
            }

            if (! ctype_xdigit($suffix)) {
                continue;
            }

            $storages[intval($suffix, 16)] = $entry;
        }

        ksort($storages, SORT_NUMERIC);

        return array_values($storages);
    }

    /**
     * Collects all MAPI properties NOT in $knownIds as RawProperty instances.
     *
     * @param string[] $knownIds 4-char hex property IDs that are already mapped to named fields
     *
     * @return RawProperty[]
     */
    private static function rawProperties(
        CompoundFile $file,
        DirectoryEntry $dir,
        PropertyStreamEntry $entry,
        array $knownIds,
        ?int $codepage = null,
    ): array {
        $raw = [];

        foreach ($entry->data as $rawKey => $propData) {
            // PHP casts pure-digit string keys to integers (e.g., '3705' → 3705).
            // The key is the decimal representation of the hex property ID, so we just
            // need to zero-pad the string form back to 4 chars.
            $hexId = self::normalizePropertyId($rawKey);
            if (in_array($hexId, $knownIds, true)) {
                continue;
            }

            $value = PropertyReader::rawValue($file, $dir, $propData, $hexId, $codepage);
            if ($value === null) {
                continue;
            }

            $raw[] = new RawProperty(
                $hexId,
                $propData->propertyType->id,
                $value,
                $propData->flags,
            );
        }

        return $raw;
    }

    /**
     * Retain the named-property mapping streams for round-trip writing.
     *
     * @return array<string, string>
     */
    private static function nameIdStreams(CompoundFile $file, DirectoryEntry $dir): array
    {
        $folder = $file->directory->get(Folders::NAME_ID_FOLDER_NAME, $dir->childId, false);
        $hasFolder = $folder instanceof DirectoryEntry;

        if (! $hasFolder) {
            return [];
        }

        $streams = [];
        foreach (self::NAME_ID_STREAMS as $name) {
            $entry = $file->directory->get($name, $folder->childId, false);
            if ($entry instanceof DirectoryEntry) {
                $streams[$name] = $file->readStreamToString($entry);
            }
        }

        return $streams;
    }

    /**
     * Resolve the Internet codepage declared by a message storage.
     */
    private static function internetCodepage(
        CompoundFile $file,
        DirectoryEntry $dir,
        PropertyStreamEntry $entry,
    ): ?int {
        $values = PropertyReader::extractValues(
            new PropertyReadContext($file, $dir, $entry),
            [Properties::$codepageProperty],
        );

        $codepage = $values['codepage'] ?? null;

        return is_int($codepage) ? $codepage : null;
    }

    /**
     * Resolve the storage encoding from codepage and locale metadata.
     */
    private static function generalCodepage(
        PropertyStreamEntry $entry,
        ?int $internetCodepage,
    ): ?int {
        $messageCodepage = self::propertyInt($entry, '3ffd');
        if ($messageCodepage !== null && ! in_array($messageCodepage, [1200, 1201], true)) {
            return $messageCodepage;
        }

        $localeCodepage = self::codepageForLocale(self::propertyInt($entry, '3ff1'));

        return $localeCodepage ?? $internetCodepage;
    }

    /**
     * Return an integer-valued property when present.
     */
    private static function propertyInt(PropertyStreamEntry $entry, string $id): ?int
    {
        $value = $entry->data[$id]->valueOrSize ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * Map a Windows locale identifier to its corresponding ANSI codepage.
     */
    private static function codepageForLocale(?int $locale): ?int
    {
        return $locale === null ? null : (self::LOCALE_CODEPAGES[$locale] ?? null);
    }

    /**
     * Returns the set of 4-char hex property IDs that are already mapped to named fields,
     * so they are excluded from raw property extraction.
     *
     * @param PropertyDefinition[][] $groups
     *
     * @return list<string>
     */
    private static function knownPropertyIds(array $groups): array
    {
        $ids = [];
        foreach ($groups as $group) {
            foreach ($group as $def) {
                $ids[] = str_pad(strtolower($def->id), 4, '0', STR_PAD_LEFT);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Normalize a MAPI identifier to four uppercase hexadecimal digits.
     */
    private static function normalizePropertyId(int|string $propertyId): string
    {
        return str_pad((string) $propertyId, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Recognize the canonical S/MIME attachment filename.
     */
    private static function smimeFileName(?string $mimeType): ?string
    {
        $type = strstr((string) $mimeType, ';', true);
        $normalized = strtolower(trim($type === false ? (string) $mimeType : $type));

        return match ($normalized) {
            'application/pkcs7-mime', 'application/x-pkcs7-mime'    => 'smime.p7m',
            'multipart/signed'                                      => 'smime.p7s',
            default                                                 => null,
        };
    }

    /**
     * Extract a lowercase attachment extension without its leading dot.
     */
    private static function extensionFromFileName(?string $fileName): ?string
    {
        $extension = pathinfo((string) $fileName, PATHINFO_EXTENSION);

        return $extension !== '' ? '.'.$extension : null;
    }

    /**
     * Resolve a MAPI attachment method with the supported default.
     */
    private static function attachmentMethod(mixed $value): ?AttachmentMethod
    {
        return is_int($value) ? AttachmentMethod::tryFrom($value) : null;
    }
}
