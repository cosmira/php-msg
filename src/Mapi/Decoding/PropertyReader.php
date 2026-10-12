<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi\Decoding;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\Mapi\PropertyData;
use Cosmira\OutlookMessage\Mapi\PropertyDefinition;
use Cosmira\OutlookMessage\Mapi\PropertyReadContext;
use Cosmira\OutlookMessage\Mapi\PropertySource;
use Cosmira\OutlookMessage\Mapi\PropertyStreamEntry;
use Cosmira\OutlookMessage\Mapi\PropertyType;
use Cosmira\OutlookMessage\Mapi\PropertyTypes;

/** @internal */
final class PropertyReader
{
    /**
     * Resolves the actual value of a PropertyData entry, reading a stream if needed.
     */
    public static function rawValue(
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
            PropertyTypes::$PtypString  => ValueDecoder::decodeUtf16($raw),
            PropertyTypes::$PtypString8 => ValueDecoder::decodeAnsi($raw, $codepage),
            default                     => $raw,
        };
    }

    /**
     * Read the known property definitions from the selected storage.
     *
     * @param PropertyDefinition[] $properties
     *
     * @return array<string, mixed>
     */
    public static function extractValues(
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

    /**
     * Resolve a property from its fixed record or dedicated value stream.
     */
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

    /**
     * Build the MAPI stream name for a property identifier and type.
     */
    private static function streamName(PropertyDefinition $property, PropertyType $type): string
    {
        return sprintf(
            '__substg1.0_%s%s',
            str_pad(strtolower($property->id), 4, '0', STR_PAD_LEFT),
            str_pad(strtolower(dechex($type->id)), 4, '0', STR_PAD_LEFT),
        );
    }

    /**
     * Find a typed value in the fixed property records.
     */
    private static function valueFromProperty(
        PropertyStreamEntry $entry,
        PropertyDefinition $property,
    ): mixed {
        $key = strtolower($property->id);
        $data = $entry->data[$key] ?? null;

        if ($data === null) {
            return null;
        }

        return $data->valueOrSize;
    }

    /**
     * Read the dedicated stream containing a property value.
     */
    private static function valueFromStream(
        CompoundFile $file,
        DirectoryEntry $entry,
        PropertyType $type,
        ?int $codepage
    ): mixed {
        $raw = $file->readStreamToString($entry);

        return match ($type) {
            PropertyTypes::$PtypString  => ValueDecoder::decodeUtf16($raw),
            PropertyTypes::$PtypString8 => ValueDecoder::decodeAnsi($raw, $codepage),
            PropertyTypes::$PtypBinary  => $raw,
            PropertyTypes::$PtypObject  => $entry,
            default                     => null,
        };
    }
}
