<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\CompoundFile\Directory;

use Cosmira\OutlookMessage\CompoundFile\Header;
use Cosmira\OutlookMessage\CompoundFile\PackedFatTable;
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
     * Return the compound file root storage entry.
     */
    public function root(): ?DirectoryEntry
    {
        $root = current($this->entries);

        return $root instanceof DirectoryEntry ? $root : null;
    }

    /**
     * Load the compound-file directory from its FAT chain.
     *
     * @param array<int, int>|PackedFatTable $fat
     *
     * @throws \Throwable
     */
    public static function load(
        BinaryBuffer $buffer,
        Header $header,
        array|PackedFatTable $fat,
    ): self {
        $entrySize = 128;
        $entriesCount = intdiv($header->sectorSize, $entrySize);

        $entries = [];
        $sector = $header->firstDirSectorLocation;
        $visitedSectors = [];

        while ($sector < 0xFFFFFFFE) {
            throw_if(
                isset($visitedSectors[$sector]),
                CorruptedFileException::class,
                'Circular reference detected in directory FAT chain.',
            );

            $visitedSectors[$sector] = true;
            $offset = Util::sectorOffset($sector, $header->sectorSize);
            $sectorBuffer = new BinaryBuffer($buffer->slice($offset, $header->sectorSize));

            for ($index = 0; $index < $entriesCount; $index++) {
                $entries[] = self::readEntry($sectorBuffer, $index * $entrySize);
            }

            $sector = $fat[$sector] ?? 0xFFFFFFFE;
        }

        $root = current($entries);
        $miniStreamStart = $root instanceof DirectoryEntry ? $root->startingSectorLocation : -1;
        $miniLocations = self::getMiniStreamLocations($miniStreamStart, $fat);

        return new self($entries, $miniLocations);
    }

    /**
     * Find a directory entry beneath the given tree root.
     *
     * @param array<int, true> $visited
     */
    public function get(string $name, int $root, bool $deep, array &$visited = []): ?DirectoryEntry
    {
        if (! $this->canVisit($root, $visited)) {
            return null;
        }

        $visited[$root] = true;
        $entry = $this->entries[$root];
        if ($this->compareName($name, $entry->entryName) === 0) {
            return $entry;
        }

        foreach ([$entry->leftSiblingId, $entry->rightSiblingId] as $sibling) {
            $match = $this->get($name, $sibling, $deep, $visited);
            if ($match instanceof DirectoryEntry) {
                return $match;
            }
        }

        return $deep ? $this->get($name, $entry->childId, true, $visited) : null;
    }

    /**
     * Reject invalid or previously visited directory identifiers.
     *
     * @param array<int, true> $visited
     */
    private function canVisit(int $root, array $visited): bool
    {
        return $root >= 0 && isset($this->entries[$root]) && ! isset($visited[$root]);
    }

    /**
     * Collect sectors backing the root mini stream.
     *
     * @param array<int, int>|PackedFatTable $fat
     *                                            FAT chain, keyed by sector index.
     *
     * @return int[]
     */
    private static function getMiniStreamLocations(int $sector, array|PackedFatTable $fat): array
    {
        $current = $sector;
        $locations = [];
        $visited = [];

        while (self::isRegularSector($current)) {
            throw_if(
                isset($visited[$current]),
                CorruptedFileException::class,
                'Circular reference detected in root mini stream chain.',
            );

            $visited[$current] = true;
            $locations[] = $current;
            $current = $fat[$current] ?? 0xFFFFFFFE;
        }

        return $locations;
    }

    /**
     * Determine whether a sector identifier addresses file data.
     */
    private static function isRegularSector(int $sector): bool
    {
        return $sector >= 0 && $sector < 0xFFFFFFFE;
    }

    /**
     * Decode one fixed-size directory record from the directory stream.
     */
    private static function readEntry(BinaryBuffer $buffer, int $offset): DirectoryEntry
    {
        $nameLength = $buffer->getUint16($offset + 64);
        $nameBytes = $nameLength > 0 ? $buffer->slice($offset, $nameLength - 2) : '';
        $name = $nameBytes === '' ? '' : mb_convert_encoding($nameBytes, 'UTF-8', 'UTF-16LE');

        // MS-CFB directory entries have fixed offsets within each 128-byte record.
        return new DirectoryEntry(
            $name,
            $nameLength,
            ObjectType::from($buffer->getUint8($offset + 66)),
            ColorFlag::from($buffer->getUint8($offset + 67)),
            $buffer->getUint32($offset + 68),
            $buffer->getUint32($offset + 72),
            $buffer->getUint32($offset + 76),
            bin2hex($buffer->slice($offset + 80, 16)),
            $buffer->getUint32($offset + 96),
            $buffer->getBigUint64($offset + 100),
            $buffer->getBigUint64($offset + 108),
            $buffer->getUint32($offset + 116),
            $buffer->getBigUint64($offset + 120),
        );
    }

    /**
     * Compare directory names using compound-file ordering rules.
     */
    private function compareName(string $expected, string $actual): int
    {
        return Util::compareDirectoryNames($expected, $actual);
    }

    /**
     * Return every direct child beneath the given storage in directory order.
     *
     * @return list<DirectoryEntry>
     */
    public function children(DirectoryEntry $parent): array
    {
        $children = [];
        $visited = [];
        $this->collectSiblingTree($parent->childId, $visited, $children);

        return $children;
    }

    /**
     * Collect the sibling tree while rejecting circular directory references.
     *
     * @param array<int, true>     $visited
     * @param list<DirectoryEntry> $children
     */
    private function collectSiblingTree(int $entryId, array &$visited, array &$children): void
    {
        if ($entryId < 0 || $entryId >= 0xFFFFFFFE) {
            return;
        }

        throw_if(
            isset($visited[$entryId]),
            CorruptedFileException::class,
            'Circular reference detected in directory sibling tree.',
        );
        $entry = $this->entries[$entryId] ?? null;
        throw_unless(
            $entry instanceof DirectoryEntry,
            CorruptedFileException::class,
            'Directory sibling reference points outside the directory stream.',
        );

        $visited[$entryId] = true;
        $this->collectSiblingTree($entry->leftSiblingId, $visited, $children);
        $children[] = $entry;
        $this->collectSiblingTree($entry->rightSiblingId, $visited, $children);
    }
}
