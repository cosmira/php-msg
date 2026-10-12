<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\CompoundFile\Directory\ColorFlag;
use Cosmira\OutlookMessage\CompoundFile\Directory\ObjectType;
use Cosmira\OutlookMessage\CompoundFile\Util;
use Cosmira\OutlookMessage\Support\BinarySource;
use Cosmira\OutlookMessage\Writer\Allocation\SectorRange;
use Cosmira\OutlookMessage\Writer\Allocation\StreamingLayout;
use RuntimeException;

class CompoundFileBuilder
{
    /**
     * Regular sector byte size used by the version-three compound-file writer.
     */
    private const SECTOR_SIZE = 512;

    /**
     * Directory identifier sentinel indicating no sibling or child.
     */
    public const NO_STREAM = 0xFFFFFFFF;

    /**
     * Allocation-table marker terminating a sector chain.
     */
    private const END_OF_CHAIN = 0xFFFFFFFE;

    /**
     * Allocation-table marker identifying a FAT sector.
     */
    private const FAT_SECTOR = 0xFFFFFFFD;

    /**
     * Allocation-table marker identifying unused space.
     */
    private const FREE_SECTOR = 0xFFFFFFFF;

    /**
     * Allocation-table marker identifying a DIFAT continuation sector.
     */
    private const DIFAT_SECTOR = 0xFFFFFFFC;

    /**
     * Largest stream length representable in a version-three compound file.
     */
    private const MAX_VERSION_THREE_STREAM_SIZE = 0xFFFFFFFF;

    /**
     * The directory entries waiting to be serialized.
     *
     * @var list<DirectoryEntryData>
     */
    private array $entries = [];

    /**
     * The stream payloads keyed by directory entry index.
     *
     * @var array<int, BinarySource>
     */
    private array $streamData = [];

    /**
     * Create an empty compound file with a root storage entry.
     */
    public function __construct()
    {
        $root = new DirectoryEntryData('Root Entry', ObjectType::RootStorage, ColorFlag::Black);
        $this->entries[] = $root;
    }

    /**
     * Get the directory index of the root storage.
     */
    public function rootIndex(): int
    {
        return 0;
    }

    /**
     * Add a storage beneath the given parent and return its index.
     */
    public function addStorage(string $name, int $parent): int
    {
        $index = count($this->entries);
        $entry = new DirectoryEntryData($name, ObjectType::Storage, ColorFlag::Black);
        $this->entries[] = $entry;
        $this->entries[$parent]->children[] = $index;

        return $index;
    }

    /**
     * Add a stream beneath the given parent and return its index.
     */
    public function addStream(string $name, string $data, int $parent): int
    {
        return $this->addStreamSource($name, BinarySource::fromString($data), $parent);
    }

    /**
     * Add a repeatable binary source beneath the given parent storage.
     */
    public function addStreamSource(string $name, BinarySource $source, int $parent): int
    {
        $size = $source->size();
        throw_if(
            $size > self::MAX_VERSION_THREE_STREAM_SIZE,
            RuntimeException::class,
            'Compound File version 3 streams cannot exceed 4 GiB minus one byte.',
        );
        $index = count($this->entries);
        $entry = new DirectoryEntryData($name, ObjectType::Stream, ColorFlag::Black);
        $entry->streamSize = BigInteger::of($size);
        $this->entries[] = $entry;
        $this->streamData[$index] = $source;
        $this->entries[$parent]->children[] = $index;

        return $index;
    }

    /**
     * Determine whether the given parent already contains a named child entry.
     */
    public function hasChild(string $name, int $parent): bool
    {
        return $this->findChild($name, $parent) !== null;
    }

    /**
     * Find a storage child by name beneath the given parent entry.
     */
    public function findStorage(string $name, int $parent): ?int
    {
        $index = $this->findChild($name, $parent);
        if ($index === null || $this->entries[$index]->type !== ObjectType::Storage) {
            return null;
        }

        return $index;
    }

    /**
     * Find a child entry by its case-insensitive CFB directory name.
     */
    private function findChild(string $name, int $parent): ?int
    {
        foreach ($this->entries[$parent]->children ?? [] as $index) {
            if (strcasecmp($this->entries[$index]->name, $name) === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Build and return the complete compound-file binary.
     */
    public function build(): string
    {
        $temporary = fopen('php://temp/maxmemory:1048576', 'w+b');
        throw_if(
            $temporary === false,
            RuntimeException::class,
            'Unable to create a temporary compound file stream.',
        );

        try {
            $this->buildTo($temporary);
            rewind($temporary);
            $binary = stream_get_contents($temporary);
            throw_if(
                $binary === false,
                RuntimeException::class,
                'Unable to materialize compound file output.',
            );

            return $binary;
        } finally {
            fclose($temporary);
        }
    }

    /**
     * Write the complete compound file to a stream without materializing large payloads.
     *
     * @param resource $destination
     */
    public function buildTo(mixed $destination): void
    {
        throw_unless(
            is_resource($destination),
            RuntimeException::class,
            'Compound file destination must be a writable stream.',
        );

        $this->buildDirectoryTrees($this->rootIndex());
        $directorySectors = max(
            1,
            intdiv(
                strlen($this->buildDirectoryStream()) + self::SECTOR_SIZE - 1,
                self::SECTOR_SIZE,
            ),
        );
        $layout = StreamingLayout::create($this->entries, $this->streamData, $directorySectors);

        $this->writeBytes($destination, $this->buildHeader(
            $layout->tables,
            0,
            $layout->miniFatStart,
            $layout->miniFatSectors,
        ));
        $this->writePadded(
            $destination,
            $this->buildDirectoryStream(),
            $directorySectors * self::SECTOR_SIZE,
        );

        $this->writePayloads($destination, $layout);

        $this->writeStreamingFat(
            $destination,
            $layout->totalSectors,
            $layout->chainRanges,
            $layout->fatSectors,
            $layout->difatSectors,
        );
        $this->writeStreamingDifat($destination, $layout->fatSectors, $layout->difatSectors);
    }

    /**
     * Copy regular and mini-stream payloads into their reserved ranges.
     *
     * @param resource $destination
     */
    private function writePayloads(mixed $destination, StreamingLayout $layout): void
    {
        foreach ($layout->regularRanges as $range) {
            $start = ftell($destination);
            throw_if(
                $start === false,
                RuntimeException::class,
                'Unable to determine compound file output position.',
            );
            $range->source?->copyTo($destination);
            $this->padToLength($destination, $start, $range->count * self::SECTOR_SIZE);
        }

        if ($layout->miniRanges !== []) {
            $start = ftell($destination);
            throw_if(
                $start === false,
                RuntimeException::class,
                'Unable to determine compound file output position.',
            );
            foreach ($layout->miniRanges as $range) {
                $miniStart = ftell($destination);
                throw_if(
                    $miniStart === false,
                    RuntimeException::class,
                    'Unable to determine mini stream output position.',
                );
                $range->source?->copyTo($destination);
                $this->padToLength($destination, $miniStart, $range->count * 64);
            }

            $this->padToLength($destination, $start, $layout->miniSectors * self::SECTOR_SIZE);
            $this->writeStreamingMiniFat(
                $destination,
                $layout->miniRanges,
                $layout->miniFatSectors,
            );
        }

    }

    /**
     * Write MiniFAT entries for contiguous small-stream ranges.
     *
     * @param resource          $destination
     * @param list<SectorRange> $ranges
     */
    private function writeStreamingMiniFat(
        mixed $destination,
        array $ranges,
        int $sectorCount,
    ): void {
        $rangeIndex = 0;
        $buffer = [];
        $capacity = $sectorCount * 128;
        for ($entry = 0; $entry < $capacity; $entry++) {
            while (isset($ranges[$rangeIndex]) && $ranges[$rangeIndex]->isPast($entry)) {
                $rangeIndex++;
            }

            $range = $ranges[$rangeIndex] ?? null;
            $value = self::FREE_SECTOR;
            if ($range !== null && $entry >= $range->start) {
                $value = $range->successor($entry);
            }

            $buffer[] = $value;
            if (count($buffer) === 128) {
                $this->writeBytes($destination, pack('V128', ...$buffer));
                $buffer = [];
            }
        }
    }

    /**
     * Write FAT sectors from compact contiguous chain descriptions.
     *
     * @param resource          $destination
     * @param list<SectorRange> $chainRanges
     * @param list<int>         $fatSectors
     * @param list<int>         $difatSectors
     */
    private function writeStreamingFat(
        mixed $destination,
        int $totalSectors,
        array $chainRanges,
        array $fatSectors,
        array $difatSectors,
    ): void {
        $fat = array_fill_keys($fatSectors, true);
        $difat = array_fill_keys($difatSectors, true);
        $rangeIndex = 0;
        $capacity = count($fatSectors) * 128;
        $buffer = [];

        for ($sector = 0; $sector < $capacity; $sector++) {
            while (isset($chainRanges[$rangeIndex]) && $chainRanges[$rangeIndex]->isPast($sector)) {
                $rangeIndex++;
            }

            $range = $chainRanges[$rangeIndex] ?? null;
            $value = $this->fatEntry($sector, $totalSectors, $fat, $difat, $range);

            $buffer[] = $value;
            if (count($buffer) === 128) {
                $this->writeBytes($destination, pack('V128', ...$buffer));
                $buffer = [];
            }
        }
    }

    /**
     * Resolve the marker or chain successor represented by one FAT slot.
     *
     * @param array<int, true> $fat
     * @param array<int, true> $difat
     */
    private function fatEntry(
        int $sector,
        int $totalSectors,
        array $fat,
        array $difat,
        ?SectorRange $range,
    ): int {
        $isChainSector = $range instanceof SectorRange && $sector >= $range->start;

        return match (true) {
            $sector < $totalSectors && isset($fat[$sector])   => self::FAT_SECTOR,
            $sector < $totalSectors && isset($difat[$sector]) => self::DIFAT_SECTOR,
            $isChainSector                                    => $range->successor($sector),
            default                                           => self::FREE_SECTOR,
        };
    }

    /**
     * Write DIFAT extension sectors after all FAT sectors.
     *
     * @param resource  $destination
     * @param list<int> $fatSectors
     * @param list<int> $difatSectors
     */
    private function writeStreamingDifat(
        mixed $destination,
        array $fatSectors,
        array $difatSectors,
    ): void {
        $extra = array_slice($fatSectors, 109);
        foreach (array_keys($difatSectors) as $index) {
            $slice = array_slice($extra, $index * 127, 127);
            $slice = array_pad($slice, 127, self::FREE_SECTOR);
            $next = $difatSectors[$index + 1] ?? self::END_OF_CHAIN;
            $this->writeBytes($destination, pack('V127', ...$slice).pack('V', $next));
        }
    }

    /**
     * Write a string followed by zero padding up to the declared byte length.
     *
     * @param resource $destination
     */
    private function writePadded(mixed $destination, string $contents, int $length): void
    {
        $this->writeBytes($destination, $contents);
        $this->writeBytes($destination, str_repeat("\0", $length - strlen($contents)));
    }

    /**
     * Pad the destination from a known start position to the declared byte length.
     *
     * @param resource $destination
     */
    private function padToLength(mixed $destination, int $start, int $length): void
    {
        $position = ftell($destination);
        throw_if(
            $position === false || $position - $start > $length,
            RuntimeException::class,
            'Binary source exceeded its allocated compound stream.',
        );
        $padding = $length - ($position - $start);
        if ($padding > 0) {
            $this->writeBytes($destination, str_repeat("\0", min($padding, self::SECTOR_SIZE)));
            $padding -= min($padding, self::SECTOR_SIZE);
            while ($padding > 0) {
                $chunk = min($padding, self::SECTOR_SIZE);
                $this->writeBytes($destination, str_repeat("\0", $chunk));
                $padding -= $chunk;
            }
        }
    }

    /**
     * Write every byte of a string to the destination stream.
     *
     * @param resource $destination
     */
    private function writeBytes(mixed $destination, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($destination, substr($contents, $offset));
            throw_if(
                $written === false || $written === 0,
                RuntimeException::class,
                'Unable to write compound file output.',
            );
            $offset += $written;
        }
    }

    /**
     * Build the sibling trees that index each storage's children.
     */
    private function buildDirectoryTrees(int $index): void
    {
        $children = $this->entries[$index]->children ?? [];
        if ($children === []) {
            $this->entries[$index]->childId = self::NO_STREAM;

            return;
        }

        usort($children, $this->compareEntryNames(...));

        $root = $this->buildBalancedTree($children);
        $this->colorDirectoryTree($root);
        $this->entries[$index]->childId = $root;

        foreach ($children as $child) {
            $this->buildDirectoryTrees($child);
        }
    }

    /**
     * Link sorted directory entries into a balanced sibling tree.
     *
     * @param list<int> $indices
     */
    private function buildBalancedTree(array $indices): int
    {
        $count = count($indices);
        if ($count === 0) {
            return self::NO_STREAM;
        }

        $mid = intdiv($count, 2);
        $root = $indices[$mid];

        $left = array_slice($indices, 0, $mid);
        $right = array_slice($indices, $mid + 1);

        $this->entries[$root]->leftSiblingId = $this->buildBalancedTree($left);
        $this->entries[$root]->rightSiblingId = $this->buildBalancedTree($right);

        return $root;
    }

    /**
     * Compare serialized directory names using compound-file ordering rules.
     */
    private function compareEntryNames(int $left, int $right): int
    {
        return Util::compareDirectoryNames(
            $this->entries[$left]->name,
            $this->entries[$right]->name,
        );
    }

    /**
     * Color the deepest level red so the balanced directory tree satisfies CFB red-black
     * invariants.
     */
    private function colorDirectoryTree(int $root): void
    {
        $maximumDepth = $this->directoryTreeDepth($root);
        $this->colorDirectorySubtree($root, 1, $maximumDepth);
    }

    /**
     * Determine the maximum depth of the generated directory tree.
     */
    private function directoryTreeDepth(int $index): int
    {
        if ($index === self::NO_STREAM) {
            return 0;
        }

        $entry = $this->entries[$index];

        return 1 + max(
            $this->directoryTreeDepth($entry->leftSiblingId),
            $this->directoryTreeDepth($entry->rightSiblingId),
        );
    }

    /**
     * Assign valid red-black colors to a height-balanced directory subtree.
     */
    private function colorDirectorySubtree(int $index, int $depth, int $maximumDepth): void
    {
        if ($index === self::NO_STREAM) {
            return;
        }

        $entry = $this->entries[$index];
        $entry->color = $depth === $maximumDepth && $depth > 1 ? ColorFlag::Red : ColorFlag::Black;
        $this->colorDirectorySubtree($entry->leftSiblingId, $depth + 1, $maximumDepth);
        $this->colorDirectorySubtree($entry->rightSiblingId, $depth + 1, $maximumDepth);
    }

    /**
     * Serialize the complete compound-file directory into padded sectors.
     */
    private function buildDirectoryStream(): string
    {
        $buffer = '';
        foreach ($this->entries as $entry) {
            $buffer .= $entry->serialize();
        }

        $remainder = strlen($buffer) % self::SECTOR_SIZE;
        if ($remainder !== 0) {
            $unallocatedEntry = str_repeat("\0", 68)
                .pack('V3', self::NO_STREAM, self::NO_STREAM, self::NO_STREAM)
                .str_repeat("\0", 48);
            $entriesToAdd = intdiv(self::SECTOR_SIZE - $remainder, 128);

            return $buffer.str_repeat($unallocatedEntry, $entriesToAdd);
        }

        return $buffer;
    }

    /**
     * Serialize the compound-file header and its inline DIFAT entries.
     */
    private function buildHeader(
        SectorLayout $layout,
        int $directoryStart,
        ?int $miniFatStart,
        int $miniFatCount,
    ): string {
        $difatStart = current($layout->difat);
        // The header DIFAT array holds the first 109 FAT sector locations.
        // Any additional FAT sector locations are chained through DIFAT extension sectors.
        $difatEntries = array_fill(0, 109, self::FREE_SECTOR);
        foreach (array_slice($layout->fat, 0, 109) as $index => $sector) {
            $difatEntries[$index] = $sector;
        }

        // Fixed MS-CFB v3 header fields, followed by the allocation-table locations.
        $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"
            .str_repeat("\0", 16)
            .pack('v5', 0x003E, 0x0003, 0xFFFE, 9, 6)
            .str_repeat("\0", 6)
            .pack(
                'V9',
                0,
                count($layout->fat),
                $directoryStart,
                0,
                4096,
                $miniFatStart ?? self::END_OF_CHAIN,
                $miniFatCount,
                $difatStart === false ? self::END_OF_CHAIN : $difatStart,
                count($layout->difat),
            )
            .pack('V109', ...$difatEntries);

        return str_pad($header, self::SECTOR_SIZE, "\0");
    }
}
