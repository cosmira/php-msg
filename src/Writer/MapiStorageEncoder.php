<?php

declare(strict_types=1);

namespace MsgViewer\Writer;

use Brick\Math\BigInteger;
use DateTimeImmutable;
use LogicException;
use MsgViewer\Mapi\Properties;
use MsgViewer\Mapi\PropertyDefinition;
use MsgViewer\Mapi\PropertySource;
use MsgViewer\Mapi\PropertyType;
use MsgViewer\Mapi\PropertyTypes;
use MsgViewer\RawProperty;

final class MapiStorageEncoder
{
    private const CODEPAGE = 65001;

    private const MESSAGE_CLASS = 'IPM.Note';

    private const ATTACH_METHOD_EMBEDDED_MESSAGE = 5;

    private const ATTACH_FLAG_RENDERED_IN_BODY = 0x04;

    public static function forMessage(MessageBuilder $builder): StorageStreams
    {
        self::bootMapi();

        $values = ['codepage' => self::CODEPAGE];
        $streams = [];

        if ($builder->date instanceof DateTimeImmutable) {
            $values['date'] = self::unixToFiletime($builder->date);
        }

        if ($builder->subject !== null) {
            $streams += self::encodeStringProperty('0037', $builder->subject);
        }

        if ($builder->senderName !== null) {
            $streams += self::encodeStringProperty('0c1a', $builder->senderName);
        }

        if ($builder->senderEmail !== null) {
            $streams += self::encodeStringProperty('0c1f', $builder->senderEmail);
        }

        if ($builder->body !== null) {
            $streams += self::encodeStringProperty('1000', $builder->body);
        }

        if ($builder->bodyHtml !== null) {
            $streams += self::encodeStringProperty('1013', $builder->bodyHtml);
        }

        if ($builder->bodyRtf !== null) {
            $streams += self::encodeBinaryProperty('1009', $builder->bodyRtf);
        }

        if ($builder->headers !== null) {
            $streams += self::encodeStringProperty('007d', $builder->headers);
        }

        self::addDisplayRecipients($streams, '0E04', $builder->recipients(), RecipientPayload::TO);
        self::addDisplayRecipients($streams, '0E03', $builder->recipients(), RecipientPayload::CC);
        self::addDisplayRecipients($streams, '0E02', $builder->recipients(), RecipientPayload::BCC);

        $streams += self::encodeStringProperty('001A', self::MESSAGE_CLASS);

        $definitions = array_merge(
            [
                new PropertyDefinition(
                    '001A',
                    'messageClass',
                    [PropertyTypes::$PtypString],
                    PropertySource::Stream
                ),
            ],
            Properties::$rootProperties,
            [Properties::$codepageProperty]
        );

        $storage = self::buildStorageStreams(
            $definitions,
            $values,
            $streams,
            true,
            count($builder->recipients()),
            count($builder->attachments()),
        );

        return $storage->appendProperties(
            self::buildRawPropertyBinary($builder->getRawProperties(), $streams),
        );
    }

    public static function forRecipient(RecipientPayload $recipient): StorageStreams
    {
        self::bootMapi();

        $streams = [];
        $values = ['type' => $recipient->type];

        if ($recipient->name !== null) {
            $streams += self::encodeStringProperty('3001', $recipient->name);
        }

        if ($recipient->email !== null) {
            $streams += self::encodeStringProperty('39fe', $recipient->email);
        }

        $storage = self::buildStorageStreams(
            Properties::$recipientProperties,
            $values,
            $streams,
            false,
        );

        return $storage->appendProperties(
            self::buildRawPropertyBinary($recipient->rawProperties, $streams),
        );
    }

    public static function forAttachment(AttachmentPayload $attachment): StorageStreams
    {
        self::bootMapi();

        $streams = [];
        $values = [];

        if ($attachment->extension !== null) {
            $streams += self::encodeStringProperty('3703', $attachment->extension);
        }

        if ($attachment->fileName !== null) {
            $streams += self::encodeStringProperty('3707', $attachment->fileName);
        }

        if ($attachment->mimeType !== null) {
            $streams += self::encodeStringProperty('370e', $attachment->mimeType);
        }

        if ($attachment->language !== null) {
            $streams += self::encodeStringProperty('3A0C', $attachment->language);
        }

        if ($attachment->displayName !== null) {
            $streams += self::encodeStringProperty('3001', $attachment->displayName);
        }

        if ($attachment->contentId !== null) {
            $streams += self::encodeStringProperty('3712', $attachment->contentId);
        }

        $streams += self::encodeBinaryProperty('3701', $attachment->content);

        if ($attachment->isInline) {
            $values['attachFlags'] = self::ATTACH_FLAG_RENDERED_IN_BODY;
        }

        $storage = self::buildStorageStreams(
            Properties::$attachmentProperties,
            $values,
            $streams,
            false,
        );

        return $storage->appendProperties(
            self::buildRawPropertyBinary($attachment->rawProperties, $streams),
        );
    }

    public static function forEmbeddedAttachment(AttachmentPayload $attachment): StorageStreams
    {
        self::bootMapi();

        throw_unless($attachment->embedded instanceof MessageBuilder, LogicException::class, 'Embedded attachments require an embedded message builder.');

        $streams = [];
        $values = ['attachMethod' => self::ATTACH_METHOD_EMBEDDED_MESSAGE];

        if ($attachment->extension !== null) {
            $streams += self::encodeStringProperty('3703', $attachment->extension);
        }

        if ($attachment->fileName !== null) {
            $streams += self::encodeStringProperty('3707', $attachment->fileName);
        }

        if ($attachment->mimeType !== null) {
            $streams += self::encodeStringProperty('370e', $attachment->mimeType);
        }

        if ($attachment->displayName !== null) {
            $streams += self::encodeStringProperty('3001', $attachment->displayName);
        }

        $storage = self::buildStorageStreams(
            Properties::$attachmentProperties,
            $values,
            $streams,
            false,
        );

        $attachDataObject = pack('V', (0x3701 << 16) | 0x000D).pack('VVV', 0, 0, 0);

        return $storage->appendProperties($attachDataObject);
    }

    /**
     * @param array<string, string> $streams
     * @param RecipientPayload[] $recipients
     */
    private static function addDisplayRecipients(array &$streams, string $propertyId, array $recipients, int $type): void
    {
        $display = array_values(array_map(
            static fn (RecipientPayload $recipient): string => $recipient->display() ?? '',
            array_filter($recipients, static fn (RecipientPayload $recipient): bool => $recipient->type === $type),
        ));

        if ($display === []) {
            return;
        }

        $streams += self::encodeStringProperty($propertyId, implode(';', $display));
    }

    /**
     * Encodes raw (unknown) MAPI properties into the property stream binary fragment.
     * Fixed-size types are encoded inline; variable-size types produce stream entries
     * that callers must add to the compound file separately.
     *
     * @param  RawProperty[]         $rawProperties
     * @param  array<string, string> $existingStreams
     */
    private static function buildRawPropertyBinary(array $rawProperties, array &$existingStreams): string
    {
        $binary = '';

        foreach ($rawProperties as $property) {
            $type = PropertyTypes::get($property->typeId);
            if (! $type instanceof PropertyType) {
                continue;
            }

            $propertyId = (int) hexdec($property->id);
            $propertyTag = ($propertyId << 16) | $property->typeId;

            if ($type->size !== null && ! $type->multi) {
                $binary .= pack('V', $propertyTag);
                $binary .= pack('V', $property->flags);
                $binary .= self::encodePropertyValue($type, $property->value);

                continue;
            }

            $raw = is_string($property->value) ? $property->value : '';
            $typeHex = str_pad(dechex($property->typeId), 4, '0', STR_PAD_LEFT);
            $streamName = sprintf('__substg1.0_%s%s', str_pad($property->id, 4, '0', STR_PAD_LEFT), $typeHex);

            if (isset($existingStreams[$streamName])) {
                continue;
            }

            $binary .= pack('V', $propertyTag);
            $binary .= pack('V', $property->flags);
            $binary .= pack('V', strlen($raw));
            $binary .= pack('V', 0);
            $existingStreams[$streamName] = $raw;
        }

        return $binary;
    }

    /**
     * @param  PropertyDefinition[]  $definitions
     * @param  array<string, mixed>  $values
     * @param  array<string, string> $streamValues
     */
    private static function buildStorageStreams(
        array $definitions,
        array $values,
        array $streamValues,
        bool $isRoot,
        int $recipientCount = 0,
        int $attachmentCount = 0,
    ): StorageStreams {
        $properties = $isRoot
            ? self::rootPropertyHeader($recipientCount, $attachmentCount)
            : str_repeat("\0", 8);

        $streams = [];

        foreach ($definitions as $definition) {
            $name = $definition->name;

            if ($definition->source === PropertySource::Property) {
                if (! array_key_exists($name, $values)) {
                    continue;
                }

                $properties .= self::encodeFixedProperty($definition, $values[$name]);

                continue;
            }

            $key = strtolower($definition->id);
            if (! array_key_exists($key, $streamValues)) {
                continue;
            }

            $data = $streamValues[$key];
            $streams[self::streamNameFor($definition, $definition->types[0])] = $data;
            $properties .= self::encodeStreamProperty($definition, strlen($data));
            unset($streamValues[$key]);
        }

        return new StorageStreams($properties, $streams);
    }

    private static function rootPropertyHeader(int $recipientCount, int $attachmentCount): string
    {
        return str_repeat("\0", 8)
            .pack('V', $recipientCount)
            .pack('V', $attachmentCount)
            .pack('V', $recipientCount)
            .pack('V', $attachmentCount)
            .str_repeat("\0", 8);
    }

    private static function encodeFixedProperty(PropertyDefinition $definition, mixed $value): string
    {
        $type = $definition->types[0];
        $propertyTag = (hexdec($definition->id) << 16) | $type->id;

        return pack('V', $propertyTag)
            .pack('V', 0)
            .self::encodePropertyValue($type, $value);
    }

    private static function encodeStreamProperty(PropertyDefinition $definition, int $length): string
    {
        $type = $definition->types[0];
        $propertyTag = (hexdec($definition->id) << 16) | $type->id;

        return pack('V', $propertyTag)
            .pack('V', 0)
            .pack('V', $length)
            .pack('V', 0);
    }

    private static function encodePropertyValue(PropertyType $type, mixed $value): string
    {
        return match ($type) {
            PropertyTypes::$PtypInteger32 => pack('V', is_int($value) ? $value : 0).pack('V', 0),
            PropertyTypes::$PtypTime => self::encodeUInt64(
                $value instanceof BigInteger || is_int($value) || is_string($value) ? $value : 0
            ),
            default => pack('V', is_int($value) ? $value : 0).pack('V', 0),
        };
    }

    /**
     * @return array<string, string>
     */
    private static function encodeStringProperty(string $id, string $value): array
    {
        return [strtolower($id) => self::encodeUnicodeString($value)];
    }

    /**
     * @return array<string, string>
     */
    private static function encodeBinaryProperty(string $id, string $value): array
    {
        return [strtolower($id) => $value];
    }

    private static function encodeUnicodeString(string $value): string
    {
        return mb_convert_encoding($value."\0", 'UTF-16LE', 'UTF-8');
    }

    private static function streamNameFor(PropertyDefinition $definition, PropertyType $type): string
    {
        return self::streamName($definition->id, $type);
    }

    private static function streamName(string $id, PropertyType $type): string
    {
        return sprintf(
            '__substg1.0_%s%s',
            strtolower(str_pad($id, 4, '0', STR_PAD_LEFT)),
            strtolower(str_pad(dechex($type->id), 4, '0', STR_PAD_LEFT)),
        );
    }

    private static function encodeUInt64(BigInteger|string|int $value): string
    {
        $bigInteger = $value instanceof BigInteger ? $value : BigInteger::of($value);
        $low = $bigInteger->mod(1 << 32)->toInt();
        $high = $bigInteger->shiftedRight(32)->toInt();

        return pack('V', $low).pack('V', $high);
    }

    private static function unixToFiletime(DateTimeImmutable $date): BigInteger
    {
        $seconds = BigInteger::of((int) $date->format('U'));
        $microseconds = BigInteger::of((int) $date->format('u'));

        return $seconds
            ->plus(self::filetimeOffsetSeconds())
            ->multipliedBy(10_000_000)
            ->plus($microseconds->multipliedBy(10));
    }

    private static function filetimeOffsetSeconds(): BigInteger
    {
        return BigInteger::of(11644473600);
    }

    private static function bootMapi(): void
    {
        Properties::init();
        PropertyTypes::init();
    }
}
