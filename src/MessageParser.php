<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Exception\EncodingException;
use Cosmira\OutlookMessage\Exception\ParseException;
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
use DateTimeImmutable;

final class MessageParser
{
    private const int FILETIME_EPOCH_OFFSET_MS = 11644473600000;

    private const int FILETIME_TICKS_PER_MS = 10000;

    private const int MAX_NESTING_DEPTH = 50;

    /** Bit flag on PR_ATTACH_FLAGS: attachment is rendered inline. */
    private const int ATTACH_FLAG_RENDEREDINBODY = 0x04;

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

        return self::fromDirectory($file, $root);
    }

    private static function fromDirectory(CompoundFile $file, DirectoryEntry $dir, int $depth = 0): Message
    {
        throw_if($depth > self::MAX_NESTING_DEPTH, ParseException::class, 'MSG nesting depth limit exceeded.');

        $propertyStream = PropertyStreamReader::forFolder($file, $dir, true);

        throw_unless($propertyStream instanceof PropertyStreamEntry, ParseException::class, 'MSG property stream is missing.');

        $knownIds = self::knownPropertyIds([Properties::$rootProperties, [Properties::$codepageProperty]]);

        return new Message(
            self::content($file, $dir, $propertyStream),
            self::attachments($file, $dir, $propertyStream->header->attachmentCount, $depth),
            self::recipients($file, $dir, $propertyStream->header->recipientCount),
            self::rawProperties($file, $dir, $propertyStream, $knownIds),
        );
    }

    private static function content(CompoundFile $file, DirectoryEntry $dir, PropertyStreamEntry $entry): MessageContent
    {
        $codepage = self::codepage($file, $dir, $entry);
        $values = self::extractValues($file, Properties::$rootProperties, $dir, $entry, $codepage);
        $bodyRtf = isset($values['bodyRtf']) && is_string($values['bodyRtf'])
            ? RtfDecompressor::decompress($values['bodyRtf'])
            : null;

        return new MessageContent(
            isset($values['date']) ? self::toDateTime(self::bigInteger($values['date'])) : null,
            self::stringOrNull($values['subject'] ?? null),
            self::stringOrNull($values['senderName'] ?? null),
            self::stringOrNull($values['senderEmail'] ?? null),
            self::stringOrNull($values['body'] ?? null),
            self::stringOrNull($values['bodyHtml'] ?? null),
            self::stringOrNull($bodyRtf),
            self::stringOrNull($values['headers'] ?? null),
            self::stringOrNull($values['to'] ?? null),
            self::stringOrNull($values['cc'] ?? null),
            self::stringOrNull($values['bcc'] ?? null),
        );
    }

    /**
     * @return Attachment[]
     */
    private static function attachments(CompoundFile $file, DirectoryEntry $dir, ?int $attachmentCount = null, int $depth = 0): array
    {
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

            $values = self::extractValues($file, Properties::$attachmentProperties, $directory, $entry);

            $embeddedDir = $values['embeddedMsgObj'] ?? null;
            $embedded = $embeddedDir instanceof DirectoryEntry
                ? self::fromDirectory($file, $embeddedDir, $depth + 1)
                : null;

            $attachFlags = isset($values['attachFlags']) ? self::intOrZero($values['attachFlags']) : 0;
            $isInline = ($attachFlags & self::ATTACH_FLAG_RENDEREDINBODY) !== 0;

            $attachments[] = new Attachment(
                self::stringOrNull($values['extension'] ?? null),
                self::stringOrNull($values['fileName'] ?? null),
                self::stringOrNull($values['mimeType'] ?? null),
                self::stringOrNull($values['language'] ?? null),
                self::stringOrNull($values['displayName'] ?? null),
                self::stringOrNull($values['content'] ?? null),
                $embedded,
                self::stringOrNull($values['contentId'] ?? null),
                $isInline,
                self::rawProperties($file, $directory, $entry, $knownIds),
            );
        }

        return $attachments;
    }

    /**
     * @return Recipient[]
     */
    private static function recipients(CompoundFile $file, DirectoryEntry $dir, ?int $recipientCount = null): array
    {
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

            $values = self::extractValues($file, Properties::$recipientProperties, $directory, $entry);

            if ($values === []) {
                continue;
            }

            $recipients[] = new Recipient(
                self::stringOrNull($values['name'] ?? null),
                self::stringOrNull($values['email'] ?? null),
                isset($values['type']) ? self::intOrZero($values['type']) : null,
                self::rawProperties($file, $directory, $entry, $knownIds),
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

            $value = self::rawValue($file, $dir, $propData, $hexId);
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
            PropertyTypes::$PtypString8 => null, // codepage unknown here; skip for raw
            PropertyTypes::$PtypBinary  => $raw,
            PropertyTypes::$PtypObject  => $raw,
            default                     => $raw,
        };
    }

    private static function codepage(CompoundFile $file, DirectoryEntry $dir, PropertyStreamEntry $entry): ?int
    {
        $values = self::extractValues($file, [Properties::$codepageProperty], $dir, $entry);

        $codepage = $values['codepage'] ?? null;

        return is_int($codepage) ? $codepage : null;
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
        ?int $codepage = null
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

                    $value = self::valueFromStream($file, $streamEntry, $type, $codepage);

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
        try {
            $decoded = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        } catch (\ValueError $valueError) {
            throw new EncodingException('Cannot decode UTF-16LE string property.', 0, $valueError);
        }

        return rtrim($decoded, "\0");
    }

    private static function decodeAnsi(string $raw, ?int $codepage): string
    {
        Properties::init();

        $encoding = Properties::$codepages[$codepage ?? 1252] ?? 'windows-1252';

        try {
            $decoded = mb_convert_encoding($raw, 'UTF-8', $encoding);
        } catch (\ValueError $valueError) {
            throw new EncodingException(
                sprintf('Cannot decode ANSI string with encoding "%s" (codepage %d).', $encoding, $codepage ?? 1252),
                0,
                $valueError,
            );
        }

        if ($decoded === false) {
            throw new EncodingException(
                sprintf('Cannot decode ANSI string with encoding "%s" (codepage %d).', $encoding, $codepage ?? 1252),
            );
        }

        return rtrim($decoded, "\0");
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

    private static function intOrZero(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private static function bigInteger(mixed $value): BigInteger
    {
        return $value instanceof BigInteger ? $value : BigInteger::zero();
    }
}
