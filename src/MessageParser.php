<?php

declare(strict_types=1);

namespace MsgViewer;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use MsgViewer\CompoundFile\CompoundFile;
use MsgViewer\CompoundFile\Directory\DirectoryEntry;
use MsgViewer\IO\BinaryBuffer;
use MsgViewer\Message\Attachment;
use MsgViewer\Message\Message;
use MsgViewer\Message\MessageContent;
use MsgViewer\Message\Recipient;
use MsgViewer\Streams\Property\Properties;
use MsgViewer\Streams\Property\PropertyDefinition;
use MsgViewer\Streams\Property\PropertySource;
use MsgViewer\Streams\Property\PropertyStreamReader;
use MsgViewer\Streams\Property\PropertyType;
use MsgViewer\Streams\Property\PropertyTypes;
use MsgViewer\Streams\Property\Types\PropertyStreamEntry;
use RuntimeException;

final class MessageParser
{
    private const EPOCH_DIFFERENCE_MS = 11644473600000;
    private const WINDOWS_TICK = 10000;

    public static function parse(string $binary): Message
    {
        Properties::init();

        $buffer = new BinaryBuffer($binary);
        $file = CompoundFile::fromBinary($buffer);

        $root = $file->directory->entries[0] ?? null;
        if (! $root instanceof DirectoryEntry) {
            throw new RuntimeException('MSG root directory is missing.');
        }

        $propertyStream = PropertyStreamReader::forFolder($file, $root);
        if ($propertyStream === null) {
            throw new RuntimeException('MSG property stream is missing.');
        }

        $content = self::getContent($file, $root, $propertyStream);
        $attachments = self::getAttachments($file, $root);
        $recipients = self::getRecipients($file, $root);

        return new Message($file, $content, $attachments, $recipients);
    }

    private static function getContent(CompoundFile $file, DirectoryEntry $dir, PropertyStreamEntry $entry): MessageContent
    {
        $codepage = self::getCodepage($file, $dir, $entry);

        $values = self::extractValues($file, Properties::$ROOT_PROPERTIES, $dir, $entry, $codepage);

        return new MessageContent(
            isset($values['date']) ? self::toDate($values['date']) : null,
            $values['subject'] ?? null,
            $values['senderName'] ?? null,
            $values['senderEmail'] ?? null,
            $values['body'] ?? null,
            $values['bodyHTML'] ?? null,
            $values['bodyRTF'] ?? null,
            $values['headers'] ?? null,
            $values['toRecipients'] ?? null,
            $values['ccRecipients'] ?? null
        );
    }

    /**
     * @return Attachment[]
     */
    private static function getAttachments(CompoundFile $file, DirectoryEntry $dir): array
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

            $values = self::extractValues($file, Properties::$ATTACH_PROPERTIES, $directory, $entry);

            $attachments[] = new Attachment(
                $values['extension'] ?? null,
                $values['fileName'] ?? null,
                $values['mimeType'] ?? null,
                $values['language'] ?? null,
                $values['displayName'] ?? null,
                $values['content'] ?? null,
                $values['embeddedMsgObj'] ?? null
            );
        }

        return $attachments;
    }

    /**
     * @return Recipient[]
     */
    private static function getRecipients(CompoundFile $file, DirectoryEntry $dir): array
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

            $values = self::extractValues($file, Properties::$RECIP_PROPERTIES, $directory, $entry);


            if(empty($values)){
                continue;
            }

            /*
            try {
                $recipients[$values['email']] = $values['name'];
            }catch (\Throwable $exception){
                dd($values);
            }
            */

             $recipients[] = new Recipient(
                $values['name'] ?? null,
                $values['email'] ?? null
             );
        }

        return $recipients;
    }

    private static function getCodepage(CompoundFile $file, DirectoryEntry $dir, PropertyStreamEntry $entry): ?int
    {
        $values = self::extractValues($file, [Properties::$CODEPAGE_PROPERTY], $dir, $entry);

        return isset($values['codepage']) ? (int) $values['codepage'] : null;
    }

    /**
     * @param PropertyDefinition[] $properties
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
                    if ($streamEntry === null) {
                        continue;
                    }

                    $value = self::getValueFromStream($file, $streamEntry, $type, $codepage);

                    if ($value !== null) {
                        $result[$property->name] = $value;
                        break;
                    }
                }
            } else {
                $value = self::getValueFromProperty($entry, $property);
                if ($value !== null) {
                    $result[$property->name] = $value;
                }
            }
        }

        return $result;
    }

    private static function getValueFromProperty(PropertyStreamEntry $entry, PropertyDefinition $property): mixed
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

    private static function getValueFromStream(
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
        $value = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');

        return rtrim($value, "\0");
    }

    private static function decodeAnsi(string $raw, ?int $codepage): string
    {
        Properties::init();
        // TODO: Говно, в ручную проставил 1251
        $encoding = Properties::$CODEPAGES[$codepage ?? 1251] ?? 'utf-8';

        $value = mb_convert_encoding($raw, 'UTF-8', $encoding);

        return rtrim($value, "\0");
    }

    /**
     * Преобразует значение FILETIME (в 100-нс тиках) в объект DateTimeImmutable.
     *
     * FILETIME хранит время как количество 100-нс интервалов, прошедших с 1 января 1601 года (UTC).
     *
     * @param BigInteger $value 64-битное значение FILETIME.
     *
     * @return DateTimeImmutable|null Возвращает дату, либо null, если значение меньше эпохи Unix.
     */
    private static function toDate(BigInteger $value): ?DateTimeImmutable
    {
        // Преобразуем тики (100 нс) в миллисекунды
        $milliseconds = $value
            ->dividedBy(self::WINDOWS_TICK, RoundingMode::HALF_UP);

        // Вычитаем разницу между эпохами FILETIME и Unix (в миллисекундах)
        $unixMilliseconds = $milliseconds->minus(self::EPOCH_DIFFERENCE_MS);

        // Если результат меньше 0 — это время до 1970-01-01, возвращаем null
        if ($unixMilliseconds->isLessThan(0)) {
            return null;
        }

        // Преобразуем миллисекунды в секунды и оставшуюся долю миллисекунд
        $seconds = intdiv((int) $unixMilliseconds->toInt(), 1000);
        $millis = $unixMilliseconds->mod(1000)->toInt();

        // Создаём объект даты с точностью до миллисекунд
        return DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%03d', $seconds, $millis)
        );
    }
}
