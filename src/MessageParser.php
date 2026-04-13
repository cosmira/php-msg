<?php

declare(strict_types=1);

namespace MsgViewer;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use MsgViewer\CompoundFile\CompoundFile;
use MsgViewer\CompoundFile\Directory\DirectoryEntry;
use MsgViewer\Mapi\Properties;
use MsgViewer\Mapi\PropertyDefinition;
use MsgViewer\Mapi\PropertySource;
use MsgViewer\Mapi\PropertyStreamEntry;
use MsgViewer\Mapi\PropertyStreamReader;
use MsgViewer\Mapi\PropertyType;
use MsgViewer\Mapi\PropertyTypes;
use MsgViewer\Support\BinaryBuffer;
use RuntimeException;

final class MessageParser
{
    private const int FILETIME_EPOCH_OFFSET_MS = 11644473600000;
    private const int FILETIME_TICKS_PER_MS = 10000;
    private const int MAX_NESTING_DEPTH = 50;

    public static function parse(string $binary): Message
    {
        Properties::init();

        $buffer = new BinaryBuffer($binary);
        $file = CompoundFile::fromBinary($buffer);

        $root = $file->directory->entries[0] ?? null;

        if (! $root instanceof DirectoryEntry) {
            throw new RuntimeException('MSG root directory is missing.');
        }

        return self::fromDirectory($file, $root);
    }

    protected static function fromDirectory(CompoundFile $file, DirectoryEntry $dir, int $depth = 0): Message
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new RuntimeException('MSG nesting depth limit exceeded.');
        }

        $propertyStream = PropertyStreamReader::forFolder($file, $dir, true);

        if ($propertyStream === null) {
            throw new RuntimeException('MSG property stream is missing.');
        }

        return new Message(
            $file,
            self::content($file, $dir, $propertyStream),
            self::attachments($file, $dir, $depth),
            self::recipients($file, $dir),
        );
    }

    protected static function content(CompoundFile $file, DirectoryEntry $dir, PropertyStreamEntry $entry): MessageContent
    {
        $codepage = self::codepage($file, $dir, $entry);
        $values = self::extractValues($file, Properties::$rootProperties, $dir, $entry, $codepage);

        return new MessageContent(
            isset($values['date']) ? self::toDateTime($values['date']) : null,
            $values['subject'] ?? null,
            $values['senderName'] ?? null,
            $values['senderEmail'] ?? null,
            $values['body'] ?? null,
            $values['bodyHtml'] ?? null,
            $values['bodyRtf'] ?? null,
            $values['headers'] ?? null,
            $values['to'] ?? null,
            $values['cc'] ?? null,
        );
    }

    /**
     * @return Attachment[]
     */
    protected static function attachments(CompoundFile $file, DirectoryEntry $dir, int $depth = 0): array
    {
        $attachments = [];

        for ($i = 0; $i < 2048; $i++) {
            $name = sprintf('__attach_version1.0_#%s', str_pad(dechex($i), 8, '0', STR_PAD_LEFT));
            $directory = $file->directory->get($name, $dir->childId, false);

            if ($directory === null) {
                break;
            }

            $entry = PropertyStreamReader::forFolder($file, $directory);

            if ($entry === null) {
                continue;
            }

            $values = self::extractValues($file, Properties::$attachmentProperties, $directory, $entry);

            $embeddedDir = $values['embeddedMsgObj'] ?? null;
            $embedded = $embeddedDir instanceof DirectoryEntry
                ? self::fromDirectory($file, $embeddedDir, $depth + 1)
                : null;

            $attachments[] = new Attachment(
                $values['extension'] ?? null,
                $values['fileName'] ?? null,
                $values['mimeType'] ?? null,
                $values['language'] ?? null,
                $values['displayName'] ?? null,
                $values['content'] ?? null,
                $embedded,
            );
        }

        return $attachments;
    }

    /**
     * @return Recipient[]
     */
    protected static function recipients(CompoundFile $file, DirectoryEntry $dir): array
    {
        $recipients = [];

        for ($i = 0; $i < 2048; $i++) {
            $name = sprintf('__recip_version1.0_#%s', str_pad(dechex($i), 8, '0', STR_PAD_LEFT));
            $directory = $file->directory->get($name, $dir->childId, false);

            if ($directory === null) {
                break;
            }

            $entry = PropertyStreamReader::forFolder($file, $directory);

            if ($entry === null) {
                continue;
            }

            $values = self::extractValues($file, Properties::$recipientProperties, $directory, $entry);

            if (empty($values)) {
                continue;
            }

            $recipients[] = new Recipient(
                $values['name'] ?? null,
                $values['email'] ?? null,
            );
        }

        return $recipients;
    }

    protected static function codepage(CompoundFile $file, DirectoryEntry $dir, PropertyStreamEntry $entry): ?int
    {
        $values = self::extractValues($file, [Properties::$codepageProperty], $dir, $entry);

        return isset($values['codepage']) ? (int) $values['codepage'] : null;
    }

    /**
     * @param PropertyDefinition[] $properties
     */
    protected static function extractValues(
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

                    if ($streamEntry === null) {
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

    protected static function valueFromProperty(PropertyStreamEntry $entry, PropertyDefinition $property): mixed
    {
        $key = strtolower($property->id);
        $data = $entry->data[$key] ?? null;

        if ($data === null) {
            return null;
        }

        $value = $data->valueOrSize;
        $type = $property->types[0] ?? null;

        if ($type instanceof PropertyType && $type === PropertyTypes::$PtypTime && $value instanceof BigInteger) {
            return $value;
        }

        return $value;
    }

    protected static function valueFromStream(
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

    protected static function decodeUtf16(string $raw): string
    {
        return rtrim(mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE'), "\0");
    }

    protected static function decodeAnsi(string $raw, ?int $codepage): string
    {
        Properties::init();

        $encoding = Properties::$codepages[$codepage ?? 1251] ?? 'utf-8';

        return rtrim(mb_convert_encoding($raw, 'UTF-8', $encoding), "\0");
    }

    /**
     * Converts a FILETIME value (100-ns ticks since 1601-01-01) to DateTimeImmutable.
     */
    protected static function toDateTime(BigInteger $value): ?DateTimeImmutable
    {
        $milliseconds = $value->dividedBy(self::FILETIME_TICKS_PER_MS, RoundingMode::HALF_UP);
        $unixMilliseconds = $milliseconds->minus(self::FILETIME_EPOCH_OFFSET_MS);

        if ($unixMilliseconds->isLessThan(0)) {
            return null;
        }

        $seconds = intdiv((int) $unixMilliseconds->toInt(), 1000);
        $millis = $unixMilliseconds->mod(1000)->toInt();

        return DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%03d', $seconds, $millis)
        );
    }
}
