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
use Cosmira\OutlookMessage\Mapi\PropertySource;
use Cosmira\OutlookMessage\Mapi\PropertyStreamEntry;
use Cosmira\OutlookMessage\Mapi\PropertyStreamReader;
use Cosmira\OutlookMessage\Mapi\PropertyType;
use Cosmira\OutlookMessage\Mapi\PropertyTypes;
use Cosmira\OutlookMessage\Rtf\RtfDecompressor;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Support\MimeType;
use Cosmira\OutlookMessage\Writer\AttachmentStorageMetadata;
use Cosmira\OutlookMessage\Writer\MessageStorageMetadata;
use DateTimeImmutable;
use DateTimeZone;

final class MessageParser
{
    private const FILETIME_EPOCH_OFFSET_MS = 11644473600000;

    private const FILETIME_TICKS_PER_MS = 10000;

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
        2057 => 1252,
        3079 => 1252,
    ];

    /**
     * Parse Outlook MSG binary into a message instance.
     */
    public static function parse(string $binary): Message
    {
        Properties::init();

        try {
            $buffer = new BinaryBuffer($binary);
            $file = CompoundFile::fromBinary($buffer);
        } catch (ParseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CorruptedFileException('Failed to parse Compound File: '.$e->getMessage(), 0, $e);
        }

        $root = $file->directory->entries[0] ?? null;

        throw_unless($root instanceof DirectoryEntry, ParseException::class, 'MSG root directory is missing.');

        $message = self::fromDirectory($file, $root, 0, true);
        MessageStorageMetadata::remember($message, $binary);

        return $message;
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
            self::attachments($file, $dir, $propertyStream->header->attachmentCount, $depth, $generalCodepage),
            self::recipients($file, $dir, $propertyStream->header->recipientCount, $generalCodepage),
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
        $bodyCodepage = $generalCodepage;
        $values = self::extractValues(
            $file,
            Properties::$rootProperties,
            $dir,
            $entry,
            $generalCodepage,
            $bodyCodepage,
            $internetCodepage ?? $generalCodepage,
        );
        $bodyRtfCompressed = isset($values['bodyRtf']) && is_string($values['bodyRtf'])
            ? $values['bodyRtf']
            : null;
        $bodyRtf = $bodyRtfCompressed !== null && $bodyRtfCompressed !== ''
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
            isset($values['bodyHtml']) && is_string($values['bodyHtml'])
                ? self::decodeHtml($values['bodyHtml'], $internetCodepage ?? $generalCodepage)
                : null,
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
        ?int $attachmentCount = null,
        int $depth = 0,
        ?int $parentCodepage = null,
    ): array {
        $attachments = [];

        $knownIds = self::knownPropertyIds([Properties::$attachmentProperties]);
        $limit = max(0, $attachmentCount ?? 65535);

        for ($i = 0; $i < $limit; $i++) {
            $name = sprintf('__attach_version1.0_#%s', str_pad(dechex($i), 8, '0', STR_PAD_LEFT));
            $directory = $file->directory->get($name, $dir->childId, false);

            if (! $directory instanceof DirectoryEntry) {
                break;
            }

            $entry = PropertyStreamReader::forFolder($file, $directory);

            if (! $entry instanceof PropertyStreamEntry) {
                continue;
            }

            $internetCodepage = self::internetCodepage($file, $directory, $entry);
            $codepage = self::generalCodepage($entry, $internetCodepage) ?? $parentCodepage;
            $values = self::extractValues($file, Properties::$attachmentProperties, $directory, $entry, $codepage);

            $embeddedDir = $values['embeddedMsgObj'] ?? null;
            $embedded = $embeddedDir instanceof DirectoryEntry
                ? self::fromDirectory($file, $embeddedDir, $depth + 1)
                : null;

            $attachFlags = isset($values['attachFlags']) ? self::intOrZero($values['attachFlags']) : 0;
            $isInline = ($attachFlags & self::ATTACH_FLAG_RENDEREDINBODY) !== 0;
            $method = self::attachmentMethod($values['attachMethod'] ?? null);
            $fileName = self::firstStringOrNull($values['fileName'] ?? null, $values['attachFileName'] ?? null);
            $mimeType = self::stringOrNull($values['mimeType'] ?? null);

            if ($method === AttachmentMethod::ByValue && $fileName === null) {
                $fileName = self::smimeFileName($mimeType);
            }

            if ($method === AttachmentMethod::ByValue && $mimeType === null) {
                $mimeType = MimeType::fromFileName($fileName);
            }

            $attachment = new Attachment(
                self::stringOrNull($values['extension'] ?? null) ?? self::extensionFromFileName($fileName),
                $fileName,
                $mimeType,
                self::stringOrNull($values['language'] ?? null),
                self::stringOrNull($values['displayName'] ?? null),
                self::stringOrNull($values['content'] ?? null),
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
        ?int $recipientCount = null,
        ?int $parentCodepage = null,
    ): array {
        $recipients = [];

        $knownIds = self::knownPropertyIds([Properties::$recipientProperties]);
        $limit = max(0, $recipientCount ?? 65535);

        for ($i = 0; $i < $limit; $i++) {
            $name = sprintf('__recip_version1.0_#%s', str_pad(dechex($i), 8, '0', STR_PAD_LEFT));
            $directory = $file->directory->get($name, $dir->childId, false);

            if (! $directory instanceof DirectoryEntry) {
                break;
            }

            $entry = PropertyStreamReader::forFolder($file, $directory);

            if (! $entry instanceof PropertyStreamEntry) {
                continue;
            }

            $internetCodepage = self::internetCodepage($file, $directory, $entry);
            $codepage = self::generalCodepage($entry, $internetCodepage) ?? $parentCodepage;
            $values = self::extractValues($file, Properties::$recipientProperties, $directory, $entry, $codepage);

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
        if ($type->size !== null && ! $type->multi) {
            return $propData->valueOrSize;
        }

        // Variable-size: look for a stream
        $typeHex = str_pad(dechex($type->id), 4, '0', STR_PAD_LEFT);
        $streamName = sprintf('__substg1.0_%s%s', $hexId, $typeHex);
        $streamEntry = $file->directory->get($streamName, $dir->childId, false);

        if (! $streamEntry instanceof DirectoryEntry) {
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
        if (! $folder instanceof DirectoryEntry) {
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
        $values = self::extractValues($file, [Properties::$codepageProperty], $dir, $entry);

        $codepage = $values['codepage'] ?? null;

        return is_int($codepage) ? $codepage : null;
    }

    private static function generalCodepage(PropertyStreamEntry $entry, ?int $internetCodepage): ?int
    {
        $messageCodepage = self::propertyInt($entry, '3ffd');
        if ($messageCodepage !== null) {
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
        CompoundFile $file,
        array $properties,
        DirectoryEntry $dir,
        PropertyStreamEntry $entry,
        ?int $codepage = null,
        ?int $bodyCodepage = null,
        ?int $htmlCodepage = null,
    ): array {
        $result = [];

        foreach ($properties as $property) {
            if ($property->source === PropertySource::Stream) {
                foreach ($property->types as $type) {
                    $streamName = sprintf(
                        '__substg1.0_%s%s',
                        str_pad(strtolower($property->id), 4, '0', STR_PAD_LEFT),
                        str_pad(strtolower(dechex($type->id)), 4, '0', STR_PAD_LEFT)
                    );

                    $streamEntry = $file->directory->get($streamName, $dir->childId, false);

                    if (! $streamEntry instanceof DirectoryEntry) {
                        continue;
                    }

                    $propertyCodepage = match ($property->name) {
                        'body'     => $bodyCodepage ?? $codepage,
                        'bodyHtml' => $htmlCodepage ?? $codepage,
                        default    => $codepage,
                    };
                    $value = self::valueFromStream($file, $streamEntry, $type, $propertyCodepage);

                    if ($value !== null) {
                        $result[$property->name] = $value;
                        break;
                    }
                }
            } else {
                $value = self::valueFromProperty($entry, $property);

                if ($value !== null) {
                    $result[$property->name] = $value;
                }
            }
        }

        return $result;
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

        return rtrim((string) mb_convert_encoding($raw, 'UTF-8', $encoding), "\0");
    }

    private static function decodeHtml(string $raw, ?int $codepage): string
    {
        if (mb_check_encoding($raw, 'UTF-8')) {
            return rtrim($raw, "\0");
        }

        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            return rtrim(mb_convert_encoding($raw, 'UTF-8', 'UTF-16'), "\0");
        }

        return self::decodeAnsi($raw, $codepage);
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
        $milliseconds = $value->quotient(self::FILETIME_TICKS_PER_MS);
        $unixMilliseconds = $milliseconds->minus(self::FILETIME_EPOCH_OFFSET_MS);

        if ($unixMilliseconds->isLessThan(0)) {
            return null;
        }

        $seconds = intdiv($unixMilliseconds->toInt(), 1000);
        $millis = $unixMilliseconds->mod(1000)->toInt();

        $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%03d', $seconds, $millis));

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
        return is_string($value) && str_contains($value, '@') ? $value : null;
    }

    private static function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        return $value instanceof BigInteger ? self::toDateTime($value) : null;
    }

    private static function submissionDateOrNull(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/-(\d{12})Z?-[^-;\x00]+/', $value, $matches) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!ymdHis', $matches[1], new DateTimeZone('UTC'));

        return $date !== false ? $date : null;
    }

    private static function smimeFileName(?string $mimeType): ?string
    {
        $normalized = strtolower(trim(explode(';', (string) $mimeType, 2)[0]));

        return match ($normalized) {
            'application/pkcs7-mime', 'application/x-pkcs7-mime'    => 'smime.p7m',
            'multipart/signed'                                      => 'smime.p7s',
            default                                                 => null,
        };
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
