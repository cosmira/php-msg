<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\Support\BinaryBuffer;

final class PropertyStreamReader
{
    /**
     * Canonical name of the MAPI metadata stream read by this reader.
     */
    private const STREAM_NAME = '__properties_version1.0';

    /**
     * Byte width of a record in this metadata stream.
     */
    private const RECORD_SIZE = 16;

    /**
     * Read the property stream stored beneath the given folder.
     */
    public static function forFolder(
        CompoundFile $file,
        DirectoryEntry $folder,
        bool $isRootMessage = false,
    ): ?PropertyStreamEntry {
        Properties::init();

        $entry = $file->directory->get(self::STREAM_NAME, $folder->childId, false);
        $hasEntry = $entry instanceof DirectoryEntry;

        if (! $hasEntry) {
            return null;
        }

        $raw = $file->readStreamToString($entry);
        if ($raw === '') {
            return new PropertyStreamEntry(new PropertyHeader(0), []);
        }

        $buffer = new BinaryBuffer($raw);

        $header = self::parseHeader($buffer, $folder->entryName, $isRootMessage);

        $data = [];
        $offset = $header->size;
        while ($buffer->hasBytes($offset, self::RECORD_SIZE)) {
            $property = self::parseProperty($buffer, $offset);
            $offset += self::RECORD_SIZE;

            $key = str_pad(strtolower(dechex($property->propertyId)), 4, '0', STR_PAD_LEFT);
            $data[$key] = $property;
        }

        return new PropertyStreamEntry($header, $data);
    }

    /**
     * Decode one property record and retain its flags and typed value.
     */
    private static function parseProperty(BinaryBuffer $buffer, int $offset): PropertyData
    {
        $propertyTag = $buffer->getUint32($offset);
        $propertyType = PropertyTypes::get($propertyTag & 0xFFFF);
        $propertyId = $propertyTag >> 16;
        $flags = $buffer->getUint32($offset + 4);
        $valueOrSize = self::readValue($buffer, $offset + 8, $propertyType);

        return new PropertyData(
            $propertyType ?? new PropertyType($propertyTag & 0xFFFF, 'Unknown', null, false),
            $propertyId,
            $flags,
            $valueOrSize
        );
    }

    /**
     * Read the fixed-size value carried by a property record.
     */
    private static function readValue(
        BinaryBuffer $buffer,
        int $offset,
        ?PropertyType $type,
    ): BigInteger|int {
        $isVariable = ! $type instanceof PropertyType || $type->size === null || $type->multi;

        if ($isVariable) {
            return $buffer->getUint32($offset);
        }

        return match ($type->size) {
            1       => $buffer->getUint8($offset),
            2       => $buffer->getUint16($offset),
            4       => $buffer->getUint32($offset),
            default => $buffer->getBigUint64($offset),
        };
    }

    /**
     * Read storage counts from a standard or compact property header.
     */
    private static function parseHeader(
        BinaryBuffer $buffer,
        string $folderName,
        bool $isRootMessage = false,
    ): PropertyHeader {
        if (self::hasCompactHeader($folderName)) {
            return new PropertyHeader(8);
        }

        $offset = 8; // Reserved

        $nextRecipientId = $buffer->getUint32($offset);
        $offset += 4;

        $nextAttachmentId = $buffer->getUint32($offset);
        $offset += 4;

        $recipientCount = $buffer->getUint32($offset);
        $offset += 4;

        $attachmentCount = $buffer->getUint32($offset);
        $offset += 4;

        $isRootFolder = str_starts_with($folderName, 'Root');

        if ($isRootMessage || $isRootFolder) {
            $offset += 8;
        }

        return new PropertyHeader(
            $offset,
            $nextRecipientId,
            $nextAttachmentId,
            $recipientCount,
            $attachmentCount
        );
    }

    /**
     * Recognize the shorter property header emitted by legacy writers.
     */
    private static function hasCompactHeader(string $folderName): bool
    {
        return str_starts_with($folderName, '__attach') || str_starts_with($folderName, '__recip');
    }
}
