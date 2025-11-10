<?php

declare(strict_types=1);

namespace MsgViewer\Streams\Property;

use Brick\Math\BigInteger;
use MsgViewer\CompoundFile\CompoundFile;
use MsgViewer\CompoundFile\Directory\DirectoryEntry;
use MsgViewer\IO\BinaryBuffer;
use MsgViewer\Streams\Property\Types\PropertyData;
use MsgViewer\Streams\Property\Types\PropertyHeader;
use MsgViewer\Streams\Property\Types\PropertyStreamEntry;
use MsgViewer\Streams\Property\PropertyType;

final class PropertyStreamReader
{
    private const STREAM_NAME = '__properties_version1.0';
    private const RECORD_SIZE = 16;

    public static function forFolder(CompoundFile $file, DirectoryEntry $folder): ?PropertyStreamEntry
    {
        Properties::init();

        $entry = $file->directory->get(self::STREAM_NAME, $folder->childId, false);
        if ($entry === null) {
            return null;
        }

        $raw = $file->readStreamToString($entry);
        $buffer = new BinaryBuffer($raw);

        $header = self::parseHeader($buffer, $folder->entryName);

        $data = [];
        $offset = $header->size;
        $length = $buffer->length();

        while (($offset + self::RECORD_SIZE) <= $length) {
            $property = self::parseProperty($buffer, $offset);
            $offset += self::RECORD_SIZE;

            $key = str_pad(strtolower(dechex($property->propertyId)), 4, '0', STR_PAD_LEFT);
            $data[$key] = $property;
        }

        return new PropertyStreamEntry($header, $data);
    }

    private static function parseProperty(BinaryBuffer $buffer, int $offset): PropertyData
    {
        $propertyTag = $buffer->getUint32($offset);
        $propertyType = PropertyTypes::get($propertyTag & 0xFFFF);
        $propertyId = $propertyTag >> 16;
        $offset += 4;

        $flags = $buffer->getUint32($offset);
        $offset += 4;

        $valueOrSize = 0;
        if ($propertyType === null || $propertyType->size === null || $propertyType->multi) {
            $valueOrSize = $buffer->getUint32($offset);
        } elseif ($propertyType->size === 1) {
            $valueOrSize = $buffer->getUint8($offset);
        } elseif ($propertyType->size === 2) {
            $valueOrSize = $buffer->getUint16($offset);
        } elseif ($propertyType->size === 4) {
            $valueOrSize = $buffer->getUint32($offset);
        } else {
            $valueOrSize = $buffer->getBigUint64($offset);
        }

        return new PropertyData(
            $propertyType ?? new PropertyType($propertyTag & 0xFFFF, 'Unknown', null, false),
            $propertyId,
            $flags,
            $valueOrSize instanceof BigInteger ? $valueOrSize : (int) $valueOrSize
        );
    }

    private static function parseHeader(BinaryBuffer $buffer, string $folderName): PropertyHeader
    {
        if (str_starts_with($folderName, '__attach') || str_starts_with($folderName, '__recip')) {
            return new PropertyHeader(8);
        }

        $offset = 0;
        $offset += 8; // Reserved

        $nextRecipientId = $buffer->getUint32($offset);
        $offset += 4;

        $nextAttachmentId = $buffer->getUint32($offset);
        $offset += 4;

        $recipientCount = $buffer->getUint32($offset);
        $offset += 4;

        $attachmentCount = $buffer->getUint32($offset);
        $offset += 4;

        if (str_starts_with($folderName, 'Root')) {
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
}

