<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\CompoundFile\Directory\ColorFlag;
use Cosmira\OutlookMessage\CompoundFile\Directory\ObjectType;
use Cosmira\OutlookMessage\CompoundFile\Util;
use Cosmira\OutlookMessage\Support\BinarySource;
use RuntimeException;

class CompoundFileBuilder
{
    private const SECTOR_SIZE = 512;

    public const NO_STREAM = 0xFFFFFFFF;

    private const END_OF_CHAIN = 0xFFFFFFFE;

    private const FAT_SECTOR = 0xFFFFFFFD;

    private const FREE_SECTOR = 0xFFFFFFFF;

    private const DIFAT_SECTOR = 0xFFFFFFFC;

    private const MAX_SECTOR_LAYOUT_ITERATIONS = 16;

    private const MAX_VERSION_THREE_STREAM_SIZE = 0xFFFFFFFF;

    /**
     * The directory entries waiting to be serialized.
     *
     * @var DirectoryEntryData[]
     */
    private array $entries = [];

    /**
     * The child entry indexes grouped by parent index.
     *
     * @var array<int, list<int>>
     */
    private array $children = [];

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
        $this->children[0] = [];
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
        $this->children[$index] = [];
        $this->children[$parent][] = $index;

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
        $this->children[$parent][] = $index;

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
        foreach ($this->children[$parent] ?? [] as $index) {
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
        throw_if($temporary === false, RuntimeException::class, 'Unable to create a temporary compound file stream.');

        try {
            $this->buildTo($temporary);
            rewind($temporary);
            $binary = stream_get_contents($temporary);
            throw_if($binary === false, RuntimeException::class, 'Unable to materialize compound file output.');

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
    public function buildTo($destination): void
    {
        throw_unless(is_resource($destination), RuntimeException::class, 'Compound file destination must be a writable stream.');

        $this->buildDirectoryTrees($this->rootIndex());
        $directorySectorCount = max(1, intdiv(strlen($this->buildDirectoryStream()) + self::SECTOR_SIZE - 1, self::SECTOR_SIZE));
        $nextSector = $directorySectorCount;
        $regularRanges = [];
        $miniRanges = [];
        $nextMiniSector = 0;

        foreach ($this->streamData as $index => $source) {
            $size = $source->size();
            $this->entries[$index]->streamSize = BigInteger::of($size);

            if ($size === 0) {
                $this->entries[$index]->startingSector = self::END_OF_CHAIN;

                continue;
            }

            if ($size < 4096) {
                $count = intdiv($size + 63, 64);
                $this->entries[$index]->startingSector = $nextMiniSector;
                $miniRanges[] = [$nextMiniSector, $count, $source];
                $nextMiniSector += $count;

                continue;
            }

            $count = intdiv($size + self::SECTOR_SIZE - 1, self::SECTOR_SIZE);
            $this->entries[$index]->startingSector = $nextSector;
            $regularRanges[] = [$nextSector, $count, $source];
            $nextSector += $count;
        }

        $miniStreamStart = null;
        $miniStreamSectorCount = 0;
        $miniFatStart = null;
        $miniFatSectorCount = 0;
        if ($nextMiniSector > 0) {
            $miniStreamStart = $nextSector;
            $miniStreamSectorCount = intdiv(($nextMiniSector * 64) + self::SECTOR_SIZE - 1, self::SECTOR_SIZE);
            $nextSector += $miniStreamSectorCount;
            $miniFatStart = $nextSector;
            $miniFatSectorCount = intdiv($nextMiniSector + 127, 128);
            $nextSector += $miniFatSectorCount;
            $this->entries[0]->startingSector = $miniStreamStart;
            $this->entries[0]->streamSize = BigInteger::of($nextMiniSector * 64);
        } else {
            $this->entries[0]->startingSector = self::END_OF_CHAIN;
            $this->entries[0]->streamSize = BigInteger::zero();
        }

        [$fatCount, $difatCount] = $this->streamingAllocationCounts($nextSector);
        $fatSectors = range($nextSector, $nextSector + $fatCount - 1);
        $nextSector += $fatCount;
        $difatSectors = $difatCount > 0 ? range($nextSector, $nextSector + $difatCount - 1) : [];
        $totalSectors = $nextSector + $difatCount;
        $layout = new SectorLayout($fatSectors, $difatSectors);

        $this->writeBytes($destination, $this->buildHeader(
            $layout,
            0,
            $miniFatStart,
            $miniFatSectorCount,
        ));
        $this->writePadded($destination, $this->buildDirectoryStream(), $directorySectorCount * self::SECTOR_SIZE);

        foreach ($regularRanges as [, $count, $source]) {
            $start = ftell($destination);
            throw_if($start === false, RuntimeException::class, 'Unable to determine compound file output position.');
            $source->copyTo($destination);
            $this->padToLength($destination, $start, $count * self::SECTOR_SIZE);
        }

        if ($miniRanges !== []) {
            $start = ftell($destination);
            throw_if($start === false, RuntimeException::class, 'Unable to determine compound file output position.');
            foreach ($miniRanges as [, $count, $source]) {
                $miniStart = ftell($destination);
                throw_if($miniStart === false, RuntimeException::class, 'Unable to determine mini stream output position.');
                $source->copyTo($destination);
                $this->padToLength($destination, $miniStart, $count * 64);
            }

            $this->padToLength($destination, $start, $miniStreamSectorCount * self::SECTOR_SIZE);
            $this->writeStreamingMiniFat($destination, $miniRanges, $miniFatSectorCount);
        }

        $chainRanges = [[0, $directorySectorCount], ...array_map(
            static fn (array $range): array => [$range[0], $range[1]],
            $regularRanges,
        )];
        if ($miniStreamStart !== null) {
            $chainRanges[] = [$miniStreamStart, $miniStreamSectorCount];
        }

        if ($miniFatStart !== null) {
            $chainRanges[] = [$miniFatStart, $miniFatSectorCount];
        }

        $this->writeStreamingFat($destination, $totalSectors, $chainRanges, $fatSectors, $difatSectors);
        $this->writeStreamingDifat($destination, $fatSectors, $difatSectors);
    }

    /**
     * Calculate converged FAT and DIFAT counts for a streaming sector layout.
     *
     * @return array{int, int}
     */
    private function streamingAllocationCounts(int $dataSectorCount): array
    {
        $fatCount = max(1, intdiv($dataSectorCount + 127, 128));
        $difatCount = 0;

        for ($iteration = 0; $iteration < self::MAX_SECTOR_LAYOUT_ITERATIONS; $iteration++) {
            $total = $dataSectorCount + $fatCount + $difatCount;
            $nextFatCount = intdiv($total + 127, 128);
            $nextDifatCount = $nextFatCount > 109 ? intdiv(($nextFatCount - 109) + 126, 127) : 0;
            if ($nextFatCount === $fatCount && $nextDifatCount === $difatCount) {
                return [$fatCount, $difatCount];
            }

            $fatCount = $nextFatCount;
            $difatCount = $nextDifatCount;
        }

        throw new RuntimeException('Failed to converge streaming FAT/DIFAT sector counts.');
    }

    /**
     * Write MiniFAT entries for contiguous small-stream ranges.
     *
     * @param resource                            $destination
     * @param list<array{int, int, BinarySource}> $ranges
     */
    private function writeStreamingMiniFat($destination, array $ranges, int $sectorCount): void
    {
        $rangeIndex = 0;
        $buffer = [];
        for ($entry = 0; $entry < $sectorCount * 128; $entry++) {
            while (isset($ranges[$rangeIndex]) && $entry >= $ranges[$rangeIndex][0] + $ranges[$rangeIndex][1]) {
                $rangeIndex++;
            }

            $range = $ranges[$rangeIndex] ?? null;
            $value = self::FREE_SECTOR;
            if ($range !== null && $entry >= $range[0]) {
                $value = $entry + 1 < $range[0] + $range[1] ? $entry + 1 : self::END_OF_CHAIN;
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
     * @param resource              $destination
     * @param list<array{int, int}> $chainRanges
     * @param list<int>             $fatSectors
     * @param list<int>             $difatSectors
     */
    private function writeStreamingFat(
        $destination,
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
            while (isset($chainRanges[$rangeIndex]) && $sector >= $chainRanges[$rangeIndex][0] + $chainRanges[$rangeIndex][1]) {
                $rangeIndex++;
            }

            $range = $chainRanges[$rangeIndex] ?? null;
            $value = self::FREE_SECTOR;
            if ($sector < $totalSectors && isset($fat[$sector])) {
                $value = self::FAT_SECTOR;
            } elseif ($sector < $totalSectors && isset($difat[$sector])) {
                $value = self::DIFAT_SECTOR;
            } elseif ($range !== null && $sector >= $range[0]) {
                $value = $sector + 1 < $range[0] + $range[1] ? $sector + 1 : self::END_OF_CHAIN;
            }

            $buffer[] = $value;
            if (count($buffer) === 128) {
                $this->writeBytes($destination, pack('V128', ...$buffer));
                $buffer = [];
            }
        }
    }

    /**
     * Write DIFAT extension sectors after all FAT sectors.
     *
     * @param resource  $destination
     * @param list<int> $fatSectors
     * @param list<int> $difatSectors
     */
    private function writeStreamingDifat($destination, array $fatSectors, array $difatSectors): void
    {
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
    private function writePadded($destination, string $contents, int $length): void
    {
        $this->writeBytes($destination, $contents);
        $this->writeBytes($destination, str_repeat("\0", $length - strlen($contents)));
    }

    /**
     * Pad the destination from a known start position to the declared byte length.
     *
     * @param resource $destination
     */
    private function padToLength($destination, int $start, int $length): void
    {
        $position = ftell($destination);
        throw_if($position === false || $position - $start > $length, RuntimeException::class, 'Binary source exceeded its allocated compound stream.');
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
    private function writeBytes($destination, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($destination, substr($contents, $offset));
            throw_if($written === false || $written === 0, RuntimeException::class, 'Unable to write compound file output.');
            $offset += $written;
        }
    }

    private function buildDirectoryTrees(int $index): void
    {
        $children = $this->children[$index] ?? [];
        if ($children === []) {
            $this->entries[$index]->childId = self::NO_STREAM;

            return;
        }

        usort($children, function (int $a, int $b): int {
            return $this->compareEntryNames($a, $b);
        });

        $root = $this->buildBalancedTree($children);
        $this->colorDirectoryTree($root);
        $this->entries[$index]->childId = $root;

        foreach ($children as $child) {
            $this->buildDirectoryTrees($child);
        }
    }

    /**
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

    private function compareEntryNames(int $a, int $b): int
    {
        return Util::compareDirectoryNames($this->entries[$a]->name, $this->entries[$b]->name);
    }

    /**
     * Color the deepest level red so the balanced directory tree satisfies CFB red-black invariants.
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

    private function buildHeader(
        SectorLayout $layout,
        int $directoryStart,
        ?int $miniFatStart,
        int $miniFatCount,
    ): string {
        $signature = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        $minorVersion = pack('v', 0x003E);
        $majorVersion = pack('v', 0x0003);
        $byteOrder = pack('v', 0xFFFE);
        $sectorShift = pack('v', 9);
        $miniSectorShift = pack('v', 6);
        $reserved = str_repeat("\0", 6);
        $directorySectors = pack('V', 0);
        $fatSectors = pack('V', count($layout->fat));
        $directoryLocation = pack('V', $directoryStart);
        $transactionSignatureNumber = pack('V', 0);
        $miniCutoff = pack('V', 4096);
        $miniFatLocation = pack('V', $miniFatStart ?? self::END_OF_CHAIN);
        $miniFatSectors = pack('V', $miniFatCount);
        $difatStart = current($layout->difat);
        $difatLocation = pack('V', $difatStart === false ? self::END_OF_CHAIN : $difatStart);
        $difatSectors = pack('V', count($layout->difat));

        // The header DIFAT array holds the first 109 FAT sector locations.
        // Any additional FAT sector locations are chained through DIFAT extension sectors.
        $difatEntries = array_fill(0, 109, self::FREE_SECTOR);
        foreach (array_slice($layout->fat, 0, 109) as $i => $sector) {
            $difatEntries[$i] = $sector;
        }

        $difat = pack('V109', ...$difatEntries);

        $header = $signature
            .str_repeat("\0", 16)
            .$minorVersion
            .$majorVersion
            .$byteOrder
            .$sectorShift
            .$miniSectorShift
            .$reserved
            .$directorySectors
            .$fatSectors
            .$directoryLocation
            .$transactionSignatureNumber
            .$miniCutoff
            .$miniFatLocation
            .$miniFatSectors
            .$difatLocation
            .$difatSectors
            .$difat;

        return str_pad($header, self::SECTOR_SIZE, "\0");
    }
}
