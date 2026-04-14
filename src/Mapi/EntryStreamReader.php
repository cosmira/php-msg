<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\Support\BinaryBuffer;

final class EntryStreamReader
{
    private const STREAM_NAME = '__substg1.0_00030102';

    private const RECORD_SIZE = 8;

    /**
     * @return EntryStreamData[]|null
     */
    public static function read(CompoundFile $file): ?array
    {
        $root = $file->directory->entries[0] ?? null;
        if ($root === null) {
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
        for ($offset = 0; $offset + self::RECORD_SIZE <= $buffer->length(); $offset += self::RECORD_SIZE) {
            $guidWithKind = $buffer->getUint16($offset + 4);
            $records[] = new EntryStreamData(
                $buffer->getUint32($offset),
                $buffer->getUint16($offset + 6),
                $guidWithKind >> 1,
                ($guidWithKind & 1) === 1 ? PropertyKind::String : PropertyKind::Numerical
            );
        }

        return $records;
    }
}
