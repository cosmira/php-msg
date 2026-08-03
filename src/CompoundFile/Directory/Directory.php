<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\CompoundFile\Directory;

use Cosmira\OutlookMessage\CompoundFile\Header;
use Cosmira\OutlookMessage\CompoundFile\Util;
use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Support\BinaryBuffer;

final readonly class Directory
{
    /**
     * Create a decoded compound-file directory.
     *
     * @param DirectoryEntry[] $entries
     * @param int[]            $miniStreamLocations
     */
    public function __construct(
        /**
         * The decoded directory entries.
         */
        public array $entries,
        /**
         * The regular sectors backing the root mini stream.
         */
        public array $miniStreamLocations
    ) {}

    /**
     * Load the compound-file directory from its FAT chain.
     *
     * @param array<int, int> $fat
     *
     * @throws \Throwable
     */
    public static function load(BinaryBuffer $buffer, Header $header, array $fat): self
    {
        $entrySize = 128;
        $entriesCount = intdiv($header->sectorSize, $entrySize);

        $entries = [];
        $sector = $header->firstDirSectorLocation;
        $visitedSectors = [];

        while ($sector < 0xFFFFFFFE) {
            throw_if(isset($visitedSectors[$sector]), CorruptedFileException::class, 'Circular reference detected in directory FAT chain.');

            $visitedSectors[$sector] = true;
            $offset = Util::sectorOffset($sector, $header->sectorSize);

            for ($i = 0; $i < $entriesCount; $i++) {
                $entries[] = self::readEntry($buffer, $offset);
                $offset += $entrySize;
            }

            $sector = $fat[$sector] ?? 0xFFFFFFFE;
        }

        $miniStreamLocations = self::getMiniStreamLocations($entries[0]->startingSectorLocation ?? -1, $fat);

        return new self($entries, $miniStreamLocations);
    }

    /**
     * Find a directory entry beneath the given tree root.
     *
     * @param array<int, true> $visited
     */
    public function get(string $name, int $root, bool $deep, array &$visited = []): ?DirectoryEntry
    {
        if ($root < 0 || ! isset($this->entries[$root]) || isset($visited[$root])) {
            return null;
        }

        $visited[$root] = true;
        $entry = $this->entries[$root];
        $diff = $this->compareName($name, $entry->entryName);

        if ($diff < 0) {
            $left = $this->get($name, $entry->leftSiblingId, $deep, $visited);
            if ($left instanceof DirectoryEntry) {
                return $left;
            }
        } elseif ($diff > 0) {
            $right = $this->get($name, $entry->rightSiblingId, $deep, $visited);
            if ($right instanceof DirectoryEntry) {
                return $right;
            }
        } else {
            return $entry;
        }

        return $deep ? $this->get($name, $entry->childId, $deep, $visited) : null;
    }

    /**
     * @param array<int, int> $fat FAT sector chain (sector index → next sector index)
     *
     * @return int[]
     */
    private static function getMiniStreamLocations(int $sector, array $fat): array
    {
        $locations = [];

        while ($sector >= 0 && $sector < 0xFFFFFFFE) {
            $locations[] = $sector;
            $sector = $fat[$sector] ?? 0xFFFFFFFE;
        }

        return $locations;
    }

    private static function readEntry(BinaryBuffer $buffer, int $offset): DirectoryEntry
    {
        $entryNameLength = $buffer->getUint16($offset + 64);
        $entryNameBytes = $entryNameLength > 0
            ? $buffer->slice($offset, $entryNameLength - 2)
            : '';
        $entryName = $entryNameBytes === ''
            ? ''
            : mb_convert_encoding($entryNameBytes, 'UTF-8', 'UTF-16LE');
        $offset += 66;

        $objectType = ObjectType::from($buffer->getUint8($offset));
        $offset += 1;

        $colorFlag = ColorFlag::from($buffer->getUint8($offset));
        $offset += 1;

        $leftSiblingId = $buffer->getUint32($offset);
        $offset += 4;

        $rightSiblingId = $buffer->getUint32($offset);
        $offset += 4;

        $childId = $buffer->getUint32($offset);
        $offset += 4;

        $clsid = bin2hex($buffer->slice($offset, 16));
        $offset += 16;

        $stateBits = $buffer->getUint32($offset);
        $offset += 4;

        $creationTime = $buffer->getBigUint64($offset);
        $offset += 8;

        $modifiedTime = $buffer->getBigUint64($offset);
        $offset += 8;

        $startingSectorLocation = $buffer->getUint32($offset);
        $offset += 4;

        $streamSize = $buffer->getBigUint64($offset);
        $offset += 8;

        return new DirectoryEntry(
            $entryName,
            $entryNameLength,
            $objectType,
            $colorFlag,
            $leftSiblingId,
            $rightSiblingId,
            $childId,
            $clsid,
            $stateBits,
            $creationTime,
            $modifiedTime,
            $startingSectorLocation,
            $streamSize
        );
    }

    private function compareName(string $expected, string $actual): int
    {
        $lenDiff = strlen($expected) <=> strlen($actual);
        if ($lenDiff !== 0) {
            return $lenDiff;
        }

        return strcasecmp($expected, $actual);
    }
}
