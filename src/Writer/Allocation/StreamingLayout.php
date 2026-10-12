<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer\Allocation;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\Support\BinarySource;
use Cosmira\OutlookMessage\Writer\DirectoryEntryData;
use Cosmira\OutlookMessage\Writer\SectorLayout;
use RuntimeException;

/** @internal */
final class StreamingLayout
{
    /**
     * Byte width of regular version-three compound sectors.
     */
    private const SECTOR_SIZE = 512;

    /**
     * Terminating allocation-table marker.
     */
    private const END_OF_CHAIN = 0xFFFFFFFE;

    /**
     * Bound on convergence of allocation-table sizing.
     */
    private const MAX_SECTOR_LAYOUT_ITERATIONS = 16;

    /**
     * Regular stream allocations in output order.
     *
     * @var list<SectorRange>
     */
    public array $regularRanges = [];

    /**
     * Small stream allocations inside the root mini stream.
     *
     * @var list<SectorRange>
     */
    public array $miniRanges = [];

    /**
     * All regular chains described by the FAT.
     *
     * @var list<SectorRange>
     */
    public array $chainRanges = [];

    /**
     * Locations of the FAT sectors.
     *
     * @var list<int>
     */
    public array $fatSectors = [];

    /**
     * Locations of DIFAT continuation sectors.
     *
     * @var list<int>
     */
    public array $difatSectors = [];

    /**
     * Sector count reserved for the root mini stream.
     */
    public int $miniSectors = 0;

    /**
     * First MiniFAT sector, if small streams exist.
     */
    public ?int $miniFatStart = null;

    /**
     * Sector count reserved for the MiniFAT.
     */
    public int $miniFatSectors = 0;

    /**
     * Total regular sector count including allocation tables.
     */
    public int $totalSectors = 0;

    /**
     * FAT and DIFAT positions used by the serialized header.
     */
    public SectorLayout $tables;

    /**
     * Allocate stream payloads, the root mini stream and the allocation tables.
     *
     * @param list<DirectoryEntryData> $entries
     * @param array<int, BinarySource> $streamData
     */
    public static function create(array $entries, array $streamData, int $directorySectors): self
    {
        $layout = new self;
        [$nextSector, $nextMiniSector] = $layout->allocateStreams(
            $entries,
            $streamData,
            $directorySectors,
        );
        $nextSector = $layout->allocateMiniStream($entries[0], $nextSector, $nextMiniSector);
        $layout->allocateTables($nextSector);
        $layout->chainRanges = [
            new SectorRange(0, $directorySectors),
            ...$layout->regularRanges,
            ...$layout->chainRanges,
        ];

        return $layout;
    }

    /**
     * Assign directory starts while allocating payload ranges in their input order.
     *
     * @param list<DirectoryEntryData> $entries
     * @param array<int, BinarySource> $streamData
     *
     * @return array{int, int}
     */
    private function allocateStreams(
        array $entries,
        array $streamData,
        int $directorySectors,
    ): array {
        $nextSector = $directorySectors;
        $nextMiniSector = 0;

        foreach ($streamData as $index => $source) {
            $size = $source->size();
            $entries[$index]->streamSize = BigInteger::of($size);

            if ($size === 0) {
                $entries[$index]->startingSector = self::END_OF_CHAIN;

                continue;
            }

            if ($size < 4096) {
                $count = intdiv($size + 63, 64);
                $entries[$index]->startingSector = $nextMiniSector;
                $this->miniRanges[] = new SectorRange($nextMiniSector, $count, $source);
                $nextMiniSector += $count;

                continue;
            }

            $count = intdiv($size + self::SECTOR_SIZE - 1, self::SECTOR_SIZE);
            $entries[$index]->startingSector = $nextSector;
            $this->regularRanges[] = new SectorRange($nextSector, $count, $source);
            $nextSector += $count;
        }

        return [$nextSector, $nextMiniSector];
    }

    /**
     * Reserve the root mini stream and MiniFAT after regular payloads.
     */
    private function allocateMiniStream(
        DirectoryEntryData $root,
        int $nextSector,
        int $nextMiniSector,
    ): int {
        if ($nextMiniSector === 0) {
            $root->startingSector = self::END_OF_CHAIN;
            $root->streamSize = BigInteger::zero();

            return $nextSector;
        }

        $this->miniSectors = intdiv(
            $nextMiniSector * 64 + self::SECTOR_SIZE - 1,
            self::SECTOR_SIZE,
        );
        $miniFatStart = $nextSector + $this->miniSectors;
        $this->miniFatStart = $miniFatStart;

        $this->miniFatSectors = intdiv($nextMiniSector + 127, 128);
        $root->startingSector = $nextSector;
        $root->streamSize = BigInteger::of($nextMiniSector * 64);
        $this->chainRanges[] = new SectorRange($nextSector, $this->miniSectors);
        $this->chainRanges[] = new SectorRange($miniFatStart, $this->miniFatSectors);

        return $miniFatStart + $this->miniFatSectors;
    }

    /**
     * Reserve the FAT and DIFAT after convergence of their own required capacity.
     */
    private function allocateTables(int $nextSector): void
    {
        [$fatCount, $difatCount] = $this->streamingAllocationCounts($nextSector);
        $this->fatSectors = range($nextSector, $nextSector + $fatCount - 1);
        $difatStart = $nextSector + $fatCount;
        $this->difatSectors = $difatCount > 0 ? range(
            $difatStart,
            $difatStart + $difatCount - 1,
        ) : [];
        $this->totalSectors = $difatStart + $difatCount;
        $this->tables = new SectorLayout($this->fatSectors, $this->difatSectors);
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
}
