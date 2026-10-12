<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer\Mapi;

use Cosmira\OutlookMessage\Mapi\Properties;
use Cosmira\OutlookMessage\Mapi\PropertyDefinition;
use Cosmira\OutlookMessage\Mapi\PropertySource;
use Cosmira\OutlookMessage\Mapi\PropertyType;
use Cosmira\OutlookMessage\Mapi\PropertyTypes;
use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Writer\PropertyValueEncoder;
use Cosmira\OutlookMessage\Writer\StorageStreams;

/** @internal */
final class PropertyStreamEncoder
{
    /**
     * Encoding of decoded message text before it is serialized into MAPI streams.
     */
    private const UTF8 = 'UTF-8';

    /**
     * Encodes raw (unknown) MAPI properties into the property stream binary fragment.
     * Fixed-size types are encoded inline; variable-size types produce stream entries
     * that callers must add to the compound file separately.
     *
     * @param RawProperty[]         $rawProperties
     * @param array<string, string> $existingStreams
     */
    private static function buildRawPropertyBinary(
        array $rawProperties,
        array &$existingStreams,
        ?int $codepage = null,
    ): string {
        $binary = '';

        foreach ($rawProperties as $property) {
            $type = PropertyTypes::get($property->typeId);
            $hasKnownType = $type instanceof PropertyType;

            if (! $hasKnownType) {
                continue;
            }

            $binary .= self::rawPropertyRecord($property, $type, $existingStreams, $codepage);
        }

        return $binary;
    }

    /**
     * Encode one raw property, adding its dedicated value stream only when absent.
     *
     * @param array<string, string> $existingStreams
     */
    private static function rawPropertyRecord(
        RawProperty $property,
        PropertyType $type,
        array &$existingStreams,
        ?int $codepage,
    ): string {
        $propertyId = (int) hexdec($property->id);
        $propertyTag = ($propertyId << 16) | $property->typeId;

        $isFixed = $type->size !== null && ! $type->multi;

        if ($isFixed) {
            return pack('V2', $propertyTag, $property->flags)
                .self::encodePropertyValue($type, $property->value);
        }

        $raw = self::rawStreamData($property, $type, $codepage);
        $typeHex = strtoupper(str_pad(dechex($property->typeId), 4, '0', STR_PAD_LEFT));
        $streamName = sprintf(
            '__substg1.0_%s%s',
            strtoupper(str_pad($property->id, 4, '0', STR_PAD_LEFT)),
            $typeHex,
        );

        if (isset($existingStreams[$streamName])) {
            return '';
        }

        $existingStreams[$streamName] = $raw;

        return pack(
            'V4',
            $propertyTag,
            $property->flags,
            self::propertyStreamLength($type, $raw),
            0,
        );
    }

    /**
     * Encode a raw string according to its declared MAPI stream type.
     */
    private static function rawStreamData(
        RawProperty $property,
        PropertyType $type,
        ?int $codepage,
    ): string {
        $raw = is_string($property->value) ? $property->value : '';

        return match ($type) {
            PropertyTypes::$PtypString  => self::encodeUnicodePayload($raw),
            PropertyTypes::$PtypString8 => self::encodeAnsiPayload($raw, $codepage),
            default                     => $raw,
        };
    }

    /**
     * Retain raw property values that are not replaced by known metadata.
     *
     * @param RawProperty[] $rawProperties
     */
    public static function appendRawProperties(
        StorageStreams $storage,
        array $rawProperties,
        ?int $codepage = null,
    ): StorageStreams {
        $streams = $storage->streams;
        $properties = self::buildRawPropertyBinary($rawProperties, $streams, $codepage);

        return new StorageStreams($storage->properties.$properties, $streams);
    }

    /**
     * Encode fixed records and dedicated streams for one MAPI storage.
     *
     * @param PropertyDefinition[]  $definitions
     * @param array<string, mixed>  $values
     * @param array<string, string> $streamValues
     */
    public static function buildStorageStreams(
        array $definitions,
        array $values,
        array $streamValues,
        bool $isRoot,
        int $recipientCount = 0,
        int $attachmentCount = 0,
        int $subStorageSize = 0,
    ): StorageStreams {
        $properties = $isRoot
            ? self::rootPropertyHeader($recipientCount, $attachmentCount)
            : str_repeat("\0", 8);

        $streams = [];
        $variableSize = 0;

        foreach ($definitions as $definition) {
            $name = $definition->name;

            if ($definition->source === PropertySource::Property) {
                $hasValue = array_key_exists($name, $values);

                if (! $hasValue) {
                    continue;
                }

                $properties .= self::encodeFixedProperty($definition, $values[$name]);

                continue;
            }

            $variableSize += self::appendDefinedStream(
                $definition,
                $streamValues,
                $streams,
                $properties,
            );
        }

        if ($isRoot) {
            $properties .= self::encodeFixedProperty(
                new PropertyDefinition(
                    '0E08',
                    'messageSize',
                    [PropertyTypes::$PtypInteger32],
                    PropertySource::Property,
                    0x00000006,
                ),
                $subStorageSize + $variableSize + 8,
            );
        }

        return new StorageStreams($properties, $streams);
    }

    /**
     * Add a defined variable property stream and report its contribution to message size.
     *
     * @param array<string, string> $streamValues
     * @param array<string, string> $streams
     */
    private static function appendDefinedStream(
        PropertyDefinition $definition,
        array &$streamValues,
        array &$streams,
        string &$properties,
    ): int {
        $key = strtolower($definition->id);
        $hasStream = array_key_exists($key, $streamValues);

        if (! $hasStream) {
            return 0;
        }

        $data = $streamValues[$key];
        $type = current($definition->types);
        $hasType = $type instanceof PropertyType;

        if (! $hasType) {
            return 0;
        }

        $streams[self::streamNameFor($definition, $type)] = $data;
        $length = self::propertyStreamLength($type, $data);
        $properties .= self::encodeStreamProperty($definition, $length);
        unset($streamValues[$key]);

        return $length;
    }

    /**
     * Serialize recipient and attachment counts for the root property stream.
     */
    private static function rootPropertyHeader(int $recipientCount, int $attachmentCount): string
    {
        return str_repeat("\0", 8)
            .pack('V', $recipientCount)
            .pack('V', $attachmentCount)
            .pack('V', $recipientCount)
            .pack('V', $attachmentCount)
            .str_repeat("\0", 8);
    }

    /**
     * Encode one fixed-size property record with its flags.
     */
    private static function encodeFixedProperty(
        PropertyDefinition $definition,
        mixed $value,
    ): string {
        $type = $definition->types[0];
        $propertyTag = (hexdec($definition->id) << 16) | $type->id;

        return pack('V', $propertyTag)
            .pack('V', $definition->flags)
            .self::encodePropertyValue($type, $value);
    }

    /**
     * Encode a property record referring to a dedicated value stream.
     */
    private static function encodeStreamProperty(
        PropertyDefinition $definition,
        int $length,
    ): string {
        $type = $definition->types[0];
        $propertyTag = (hexdec($definition->id) << 16) | $type->id;

        return pack('V', $propertyTag)
            .pack('V', $definition->flags)
            .pack('V', $length)
            .pack('V', 0);
    }

    /**
     * Measure the encoded stream length required by a property record.
     */
    private static function propertyStreamLength(PropertyType $type, string $data): int
    {
        return match ($type) {
            PropertyTypes::$PtypString  => strlen($data) + 2,
            PropertyTypes::$PtypString8 => strlen($data) + 1,
            default                     => strlen($data),
        };
    }

    /**
     * Encode a fixed property value according to its MAPI type.
     */
    private static function encodePropertyValue(PropertyType $type, mixed $value): string
    {
        return PropertyValueEncoder::encode($type, $value);
    }

    /**
     * Encode UTF-8 text as terminated UTF-16LE.
     */
    public static function encodeUnicodeString(string $value): string
    {
        return mb_convert_encoding($value."\0", 'UTF-16LE', self::UTF8);
    }

    /**
     * Encode UTF-8 text as UTF-16LE without a terminator.
     */
    public static function encodeUnicodePayload(string $value): string
    {
        return mb_convert_encoding($value, 'UTF-16LE', self::UTF8);
    }

    /**
     * Encode text in the message's ANSI codepage without a terminator.
     */
    private static function encodeAnsiPayload(string $value, ?int $codepage): string
    {
        $encoding = Properties::$codepages[$codepage ?? 1252] ?? 'windows-1252';

        return mb_convert_encoding($value, $encoding, self::UTF8);
    }

    /**
     * Build a dedicated stream name from a property definition.
     */
    private static function streamNameFor(
        PropertyDefinition $definition,
        PropertyType $type,
    ): string {
        return self::streamName($definition->id, $type);
    }

    /**
     * Build the MAPI stream name for a property identifier and type.
     */
    private static function streamName(string $id, PropertyType $type): string
    {
        return sprintf(
            '__substg1.0_%s%s',
            strtoupper(str_pad($id, 4, '0', STR_PAD_LEFT)),
            strtoupper(str_pad(dechex($type->id), 4, '0', STR_PAD_LEFT)),
        );
    }
}
