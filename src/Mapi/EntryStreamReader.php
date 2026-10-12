<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\Support\BinaryBuffer;

final class EntryStreamReader
{
    /**
     * Canonical name of the MAPI metadata stream read by this reader.
     */
    private const STREAM_NAME = '__substg1.0_00030102';

    /**
     * Byte width of a record in this metadata stream.
     */
    private const RECORD_SIZE = 8;

    /**
     * Read the NameID entry stream from the given compound file.
     *
     * @return EntryStreamData[]|null
     */
    public static function read(CompoundFile $file): ?array
    {
        $root = $file->directory->root();

        if (! $root instanceof DirectoryEntry) {
            return null;
        }

        $folder = $file->directory->get(Folders::NAME_ID_FOLDER_NAME, $root->childId, false);

        if (! $folder instanceof DirectoryEntry) {
            return null;
        }

        $entry = $file->directory->get(self::STREAM_NAME, $folder->childId, false);

        if (! $entry instanceof DirectoryEntry) {
            return null;
        }

        $raw = $file->readStreamToString($entry);
        $buffer = new BinaryBuffer($raw);

        $records = [];
        for ($offset = 0; $buffer->hasBytes(
            $offset,
            self::RECORD_SIZE,
        ); $offset += self::RECORD_SIZE) {
            $guidWithKind = $buffer->getUint16($offset + 4);
            $isString = ($guidWithKind & 1) === 1;
            $kind = $isString ? PropertyKind::String : PropertyKind::Numerical;
            $records[] = new EntryStreamData(
                $buffer->getUint32($offset),
                $buffer->getUint16($offset + 6),
                $guidWithKind >> 1,
                $kind,
            );
        }

        return $records;
    }
}
