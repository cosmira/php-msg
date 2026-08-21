<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Exception\ParseException;
use Cosmira\OutlookMessage\Mapi\Folders;
use Cosmira\OutlookMessage\Mapi\Properties;
use Cosmira\OutlookMessage\Mapi\PropertyData;
use Cosmira\OutlookMessage\Mapi\PropertyDefinition;
use Cosmira\OutlookMessage\Mapi\PropertyReadContext;
use Cosmira\OutlookMessage\Mapi\PropertySource;
use Cosmira\OutlookMessage\Mapi\PropertyStreamEntry;
use Cosmira\OutlookMessage\Mapi\PropertyStreamReader;
use Cosmira\OutlookMessage\Mapi\PropertyType;
use Cosmira\OutlookMessage\Mapi\PropertyTypes;
use Cosmira\OutlookMessage\Rtf\RtfDecompressor;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Support\BinarySource;
use Cosmira\OutlookMessage\Support\MimeType;
use Cosmira\OutlookMessage\Writer\AttachmentStorageMetadata;
use Cosmira\OutlookMessage\Writer\MessageStorageMetadata;
use DateTimeImmutable;
use DateTimeZone;

final class MessageParser
{
    private const FILETIME_EPOCH_OFFSET_MICROSECONDS = 11644473600000000;

    private const FILETIME_TICKS_PER_MICROSECOND = 10;

    private const MAX_NESTING_DEPTH = 50;

    /**
     * Bit flag on PR_ATTACH_FLAGS: attachment is rendered inline.
     */
    private const ATTACH_FLAG_RENDEREDINBODY = 0x04;

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
        } catch (ParseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CorruptedFileException('Unable to read message from "'.$path.'": '.$e->getMessage(), 0, $e);
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
        } catch (ParseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CorruptedFileException('Failed to parse Compound File: '.$e->getMessage(), 0, $e);
        }

        $root = $file->directory->root();

        throw_unless($root instanceof DirectoryEntry, ParseException::class, 'MSG root directory is missing.');

        return self::fromDirectory($file, $root, 0, true);
    }

    private static function fromDirectory(CompoundFile $file, DirectoryEntry $dir, int $depth = 0, bool $includeNameId = false): Message
    {
        throw_if($depth > self::MAX_NESTING_DEPTH, ParseException::class, 'MSG nesting depth limit exceeded.');

        $propertyStream = PropertyStreamReader::forFolder($file, $dir, $includeNameId);

        throw_unless($propertyStream instanceof PropertyStreamEntry, ParseException::class, 'MSG property stream is missing.');

        $knownIds = self::knownPropertyIds([Properties::$rootProperties, [Properties::$codepageProperty]]);
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
        $values = self::extractValues(
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
        $bodyRtfCompressed = self::stringOrNull($values['bodyRtf'] ?? null);
        $hasRtf = $bodyRtfCompressed !== null && $bodyRtfCompressed !== '';
        $bodyRtf = $hasRtf
            ? RtfDecompressor::decompress($bodyRtfCompressed)
            : null;
        $date = self::submissionDateOrNull($values['messageSubmissionId'] ?? null)
            ?? self::dateOrNull($values['clientSubmitTime'] ?? null)
            ?? self::dateOrNull($values['date'] ?? null);
        $actualSenderName = self::stringOrNull($values['senderName'] ?? null);
        $actualSenderEmail = self::firstEmailOrNull(
            $values['senderSmtpEmail'] ?? null,
            $values['senderAlternateSmtpEmail'] ?? null,
            $values['senderEmail'] ?? null,
        );
        $representingName = self::stringOrNull($values['sentRepresentingName'] ?? null);
        $representingEmail = self::firstEmailOrNull($values['sentRepresentingEmail'] ?? null);
        $messageFlags = self::intOrZero($values['messageFlags'] ?? null);

        return new MessageContent(
            $date,
            self::stringOrNull($values['subject'] ?? null),
            $representingName ?? $actualSenderName,
            $representingEmail ?? $actualSenderEmail,
            self::stringOrNull($values['body'] ?? null),
            self::htmlOrNull($values['bodyHtml'] ?? null, $internetCodepage ?? $generalCodepage),
            self::stringOrNull($bodyRtf),
            self::stringOrNull($values['headers'] ?? null),
            bodyRtfCompressed: $bodyRtfCompressed,
            receivedAt: self::dateOrNull($values['date'] ?? null),
            actualSenderName: $actualSenderName,
            actualSenderEmail: $actualSenderEmail,
            representingName: $representingName,
            representingEmail: $representingEmail,
            importance: self::intOrNull($values['importance'] ?? null),
            priority: self::intOrNull($values['priority'] ?? null),
            draft: ($messageFlags & 0x0008) !== 0,
            readReceiptRequested: self::intOrZero($values['readReceiptRequested'] ?? null) !== 0,
            iconIndex: self::intOrNull($values['iconIndex'] ?? null),
            editorFormat: self::intOrNull($values['messageEditorFormat'] ?? null),
            internetMessageId: self::stringOrNull($values['internetMessageId'] ?? null),
            internetReferences: self::stringOrNull($values['internetReferences'] ?? null),
            inReplyToId: self::stringOrNull($values['inReplyToId'] ?? null),
            messageClass: self::stringOrNull($values['messageClass'] ?? null),
            conversationTopic: self::stringOrNull($values['conversationTopic'] ?? null),
            messageSubmissionId: self::stringOrNull($values['messageSubmissionId'] ?? null),
            codepage: $generalCodepage,
            messageLocaleId: self::intOrNull($values['messageLocaleId'] ?? null),
        );
    }

    /**
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
            $values = self::extractValues(
                new PropertyReadContext($file, $directory, $entry, $codepage),
                array_values(array_filter(
                    Properties::$attachmentProperties,
                    static fn (PropertyDefinition $property): bool => $property->name !== 'content',
                )),
            );

            $attachFlags = isset($values['attachFlags']) ? self::intOrZero($values['attachFlags']) : 0;
            $isInline = ($attachFlags & self::ATTACH_FLAG_RENDEREDINBODY) !== 0;
            $method = self::attachmentMethod($values['attachMethod'] ?? null);
            $embeddedDir = $values['embeddedMsgObj'] ?? null;
            $embedded = $method === AttachmentMethod::EmbeddedMessage && $embeddedDir instanceof DirectoryEntry
                ? self::fromDirectory($file, $embeddedDir, $depth + 1)
                : null;
            $fileName = self::firstStringOrNull($values['fileName'] ?? null, $values['attachFileName'] ?? null);
            $mimeType = self::stringOrNull($values['mimeType'] ?? null);

            $needsFileName = $method === AttachmentMethod::ByValue && $fileName === null;

            if ($needsFileName) {
                $fileName = self::smimeFileName($mimeType);
            }

            $needsMimeType = $method === AttachmentMethod::ByValue && $mimeType === null;

            if ($needsMimeType) {
                $mimeType = MimeType::fromFileName($fileName);
            }

            $contentEntry = $file->directory->get('__substg1.0_37010102', $directory->childId, false);
            $content = $contentEntry instanceof DirectoryEntry
                ? BinarySource::fromWriter(
                    $contentEntry->streamSize->toInt(),
                    static function ($destination) use ($file, $contentEntry): void {
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

            $attachment = new Attachment(
                self::stringOrNull($values['extension'] ?? null) ?? self::extensionFromFileName($fileName),
                $fileName,
                $mimeType,
                self::stringOrNull($values['language'] ?? null),
                self::stringOrNull($values['displayName'] ?? null),
                $content,
                $embedded,
                self::stringOrNull($values['contentId'] ?? null),
                $isInline,
                self::rawProperties($file, $directory, $entry, $knownIds, $codepage),
                $method,
            );
            AttachmentStorageMetadata::rememberRenderingPosition(
                $attachment,
                self::intOrNull($values['renderingPosition'] ?? null),
            );
            if ($method instanceof AttachmentMethod && ! in_array($method, [AttachmentMethod::ByValue, AttachmentMethod::EmbeddedMessage], true)) {
                AttachmentStorageMetadata::rememberOpaqueAttachment($attachment);
            }

            $attachments[] = $attachment;
        }

        return $attachments;
    }

    /**
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
            $values = self::extractValues(
                new PropertyReadContext($file, $directory, $entry, $codepage),
                Properties::$recipientProperties,
            );

            if ($values === []) {
                continue;
            }

            $name = self::stringOrNull($values['name'] ?? null);
            $email = self::stringOrNull($values['email'] ?? null);

            $recipients[] = new Recipient(
                $name,
                $email ?? self::emailOrNull($name),
                isset($values['type']) ? self::intOrZero($values['type']) : null,
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

            $value = self::rawValue($file, $dir, $propData, $hexId, $codepage);
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
     * Resolves the actual value of a PropertyData entry, reading a stream if needed.
     */
    private static function rawValue(
        CompoundFile $file,
        DirectoryEntry $dir,
        PropertyData $propData,
        string $hexId,
        ?int $codepage = null,
    ): mixed {
        $type = $propData->propertyType;

        // Fixed-size types
        $isFixed = $type->size !== null && ! $type->multi;

        if ($isFixed) {
            return $propData->valueOrSize;
        }

        // Variable-size: look for a stream
        $typeHex = str_pad(dechex($type->id), 4, '0', STR_PAD_LEFT);
        $streamName = sprintf('__substg1.0_%s%s', $hexId, $typeHex);
        $streamEntry = $file->directory->get($streamName, $dir->childId, false);

        $hasStream = $streamEntry instanceof DirectoryEntry;

        if (! $hasStream) {
            return null;
        }

        $raw = $file->readStreamToString($streamEntry);

        return match ($type) {
            PropertyTypes::$PtypString  => self::decodeUtf16($raw),
            PropertyTypes::$PtypString8 => self::decodeAnsi($raw, $codepage),
            PropertyTypes::$PtypBinary  => $raw,
            PropertyTypes::$PtypObject  => $raw,
            default                     => $raw,
        };
    }

    /**
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

    private static function internetCodepage(CompoundFile $file, DirectoryEntry $dir, PropertyStreamEntry $entry): ?int
    {
        $values = self::extractValues(
            new PropertyReadContext($file, $dir, $entry),
            [Properties::$codepageProperty],
        );

        $codepage = $values['codepage'] ?? null;

        return is_int($codepage) ? $codepage : null;
    }

    private static function generalCodepage(PropertyStreamEntry $entry, ?int $internetCodepage): ?int
    {
        $messageCodepage = self::propertyInt($entry, '3ffd');
        if ($messageCodepage !== null && ! in_array($messageCodepage, [1200, 1201], true)) {
            return $messageCodepage;
        }

        $localeCodepage = self::codepageForLocale(self::propertyInt($entry, '3ff1'));

        return $localeCodepage ?? $internetCodepage;
    }

    /**
     * @param PropertyDefinition[] $properties
     *
     * @return array<string, mixed>
     */
    private static function extractValues(
        PropertyReadContext $context,
        array $properties,
    ): array {
        $result = [];

        foreach ($properties as $property) {
            $value = self::extractValue(
                $context,
                $property,
            );

            if ($value !== null) {
                $result[$property->name] = $value;
            }
        }

        return $result;
    }

    private static function extractValue(
        PropertyReadContext $context,
        PropertyDefinition $property,
    ): mixed {
        if ($property->source !== PropertySource::Stream) {
            return self::valueFromProperty($context->properties, $property);
        }

        foreach ($property->types as $type) {
            $streamEntry = $context->file->directory->get(
                self::streamName($property, $type),
                $context->directory->childId,
                false,
            );

            if ($streamEntry instanceof DirectoryEntry) {
                return self::valueFromStream(
                    $context->file,
                    $streamEntry,
                    $type,
                    $context->codepageFor($property),
                );
            }
        }

        return null;
    }

    private static function streamName(PropertyDefinition $property, PropertyType $type): string
    {
        return sprintf(
            '__substg1.0_%s%s',
            str_pad(strtolower($property->id), 4, '0', STR_PAD_LEFT),
            str_pad(strtolower(dechex($type->id)), 4, '0', STR_PAD_LEFT),
        );
    }

    private static function valueFromProperty(PropertyStreamEntry $entry, PropertyDefinition $property): mixed
    {
        $key = strtolower($property->id);
        $data = $entry->data[$key] ?? null;

        if ($data === null) {
            return null;
        }

        return $data->valueOrSize;
    }

    private static function valueFromStream(
        CompoundFile $file,
        DirectoryEntry $entry,
        PropertyType $type,
        ?int $codepage
    ): mixed {
        $raw = $file->readStreamToString($entry);

        return match ($type) {
            PropertyTypes::$PtypString  => self::decodeUtf16($raw),
            PropertyTypes::$PtypString8 => self::decodeAnsi($raw, $codepage),
            PropertyTypes::$PtypBinary  => $raw,
            PropertyTypes::$PtypObject  => $entry,
            default                     => null,
        };
    }

    private static function decodeUtf16(string $raw): string
    {
        return rtrim(mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE'), "\0");
    }

    private static function decodeAnsi(string $raw, ?int $codepage): string
    {
        Properties::init();

        $encoding = Properties::$codepages[$codepage ?? 1252] ?? 'windows-1252';

        if (! mb_check_encoding($raw, $encoding)) {
            $encoding = self::detectLegacyEncoding($raw, $encoding) ?? $encoding;
        }

        $decoded = mb_convert_encoding($raw, 'UTF-8', $encoding);

        return rtrim($decoded === false ? $raw : $decoded, "\0");
    }

    private static function decodeHtml(string $raw, ?int $codepage): string
    {
        $hasBom = str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF");

        if ($hasBom) {
            $decoded = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');

            return rtrim($decoded, "\0");
        }

        if (mb_check_encoding($raw, 'UTF-8')) {
            return rtrim($raw, "\0");
        }

        $declaredEncoding = self::htmlDeclaredEncoding($raw);
        if ($declaredEncoding !== null && mb_check_encoding($raw, $declaredEncoding)) {
            $decoded = mb_convert_encoding($raw, 'UTF-8', $declaredEncoding);

            return $decoded === false ? self::decodeAnsi($raw, $codepage) : rtrim($decoded, "\0");
        }

        return self::decodeAnsi($raw, $codepage);
    }

    /**
     * Detect a compatible legacy Windows encoding after the declared encoding fails.
     */
    private static function detectLegacyEncoding(string $raw, string $declared): ?string
    {
        $supported = array_fill_keys(array_map(strtolower(...), mb_list_encodings()), true);
        $candidates = array_values(array_unique(array_filter(
            ['CP936', 'CP950', 'CP949', 'SJIS-win', 'Windows-1252', 'Windows-1251'],
            static fn (string $encoding): bool => strcasecmp($encoding, $declared) !== 0
                && isset($supported[strtolower($encoding)]),
        )));
        $encoding = mb_detect_encoding($raw, $candidates, true);

        return is_string($encoding) ? $encoding : null;
    }

    /**
     * Extract a supported charset declaration from the leading HTML markup.
     */
    private static function htmlDeclaredEncoding(string $raw): ?string
    {
        $prefix = substr($raw, 0, 8192);
        $matched = preg_match('/charset\s*=\s*["\']?\s*([a-z0-9._-]+)/i', $prefix, $matches) === 1;

        if (! $matched) {
            return null;
        }

        $encoding = $matches[1];

        try {
            if (! mb_check_encoding('', $encoding)) {
                return null;
            }

            return $encoding;
        } catch (\ValueError) {
            return null;
        }
    }

    private static function propertyInt(PropertyStreamEntry $entry, string $id): ?int
    {
        $value = $entry->data[$id]->valueOrSize ?? null;

        return is_int($value) ? $value : null;
    }

    private static function codepageForLocale(?int $locale): ?int
    {
        return $locale === null ? null : (self::LOCALE_CODEPAGES[$locale] ?? null);
    }

    /**
     * Converts a FILETIME value (100-ns ticks since 1601-01-01) to DateTimeImmutable.
     */
    private static function toDateTime(BigInteger $value): ?DateTimeImmutable
    {
        $microseconds = $value->quotient(self::FILETIME_TICKS_PER_MICROSECOND);
        $unixMicroseconds = $microseconds->minus(self::FILETIME_EPOCH_OFFSET_MICROSECONDS);

        if ($unixMicroseconds->isLessThan(0)) {
            return null;
        }

        $seconds = $unixMicroseconds->quotient(1_000_000)->toInt();
        $micros = $unixMicroseconds->mod(1_000_000)->toInt();

        $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $micros));

        return $dt !== false ? $dt : null;
    }

    /**
     * Returns the set of 4-char hex property IDs that are already mapped to named fields,
     * so they are excluded from raw property extraction.
     *
     * @param PropertyDefinition[][] $groups
     *
     * @return string[]
     */
    private static function knownPropertyIds(array $groups): array
    {
        $ids = [];
        foreach ($groups as $group) {
            foreach ($group as $def) {
                $ids[] = str_pad(strtolower($def->id), 4, '0', STR_PAD_LEFT);
            }
        }

        return array_unique($ids);
    }

    private static function normalizePropertyId(int|string $propertyId): string
    {
        return str_pad((string) $propertyId, 4, '0', STR_PAD_LEFT);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function firstStringOrNull(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function firstEmailOrNull(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $email = self::emailOrNull($value);
            if ($email !== null) {
                return $email;
            }
        }

        return null;
    }

    private static function emailOrNull(mixed $value): ?string
    {
        $isEmail = is_string($value) && str_contains($value, '@');

        return $isEmail ? $value : null;
    }

    private static function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        return $value instanceof BigInteger ? self::toDateTime($value) : null;
    }

    private static function submissionDateOrNull(mixed $value): ?DateTimeImmutable
    {
        $isString = is_string($value);

        if (! $isString) {
            return null;
        }

        $hasDate = preg_match('/-(?P<date>\d{12})Z?-[^-;\x00]+/', $value, $matches) === 1;

        if (! $hasDate) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!ymdHis', $matches['date'], new DateTimeZone('UTC'));

        return $date !== false ? $date : null;
    }

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

    private static function htmlOrNull(mixed $value, ?int $codepage): ?string
    {
        return is_string($value) ? self::decodeHtml($value, $codepage) : null;
    }

    private static function extensionFromFileName(?string $fileName): ?string
    {
        $extension = pathinfo((string) $fileName, PATHINFO_EXTENSION);

        return $extension !== '' ? '.'.$extension : null;
    }

    private static function intOrZero(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private static function attachmentMethod(mixed $value): ?AttachmentMethod
    {
        return is_int($value) ? AttachmentMethod::tryFrom($value) : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
