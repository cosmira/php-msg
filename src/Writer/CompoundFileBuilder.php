<?php

declare(strict_types=1);

namespace MsgViewer\Writer;

use Brick\Math\BigInteger;
use MsgViewer\CompoundFile\Directory\Enums\ColorFlag;
use MsgViewer\CompoundFile\Directory\Enums\ObjectType;

class CompoundFileBuilder
{
    private const SECTOR_SIZE = 512;
    public const NO_STREAM = 0xFFFFFFFF;
    private const END_OF_CHAIN = 0xFFFFFFFE;
    private const FAT_SECTOR = 0xFFFFFFFD;
    private const FREE_SECTOR = 0xFFFFFFFF;

    /**
     * @var DirectoryEntryData[]
     */
    private array $entries = [];

    /**
     * @var array<int, list<int>>
     */
    private array $children = [];

    /**
     * @var array<int, string>
     */
    private array $streamData = [];

    /**
     * @var array<int, list<int>>
     */
    private array $sectorChains = [];

    /**
     * @var list<string>
     */
    private array $sectors = [];

    private ?int $miniFatStart = null;
    private int $miniFatSectorCount = 0;

    public function __construct()
    {
        $root = new DirectoryEntryData('Root Entry', ObjectType::RootStorage, ColorFlag::Black);
        $this->entries[] = $root;
        $this->children[0] = [];
    }

    public function rootIndex(): int
    {
        return 0;
    }

    public function addStorage(string $name, int $parent): int
    {
        $index = count($this->entries);
        $entry = new DirectoryEntryData($name, ObjectType::Storage, ColorFlag::Black);
        $this->entries[] = $entry;
        $this->children[$index] = [];
        $this->children[$parent][] = $index;

        return $index;
    }

    public function addStream(string $name, string $data, int $parent): int
    {
        $index = count($this->entries);
        $entry = new DirectoryEntryData($name, ObjectType::Stream, ColorFlag::Black);
        $entry->streamSize = BigInteger::of(strlen($data));
        $this->entries[] = $entry;
        $this->streamData[$index] = $data;
        $this->children[$parent][] = $index;

        return $index;
    }

    public function build(): string
    {
        $this->buildDirectoryTrees($this->rootIndex());
        $this->allocateStreams();

        $directoryStream = $this->buildDirectoryStream();
        $directoryStart = $this->appendStreamSectors($directoryStream);
        $directoryChain = $this->sectorChains[$directoryStart];

        $dataSectorCount = count($this->sectors);
        $fatSectorCount = max(1, (int) ceil($dataSectorCount / 128));

        while (true) {
            $totalSectors = $dataSectorCount + $fatSectorCount;
            $requiredFat = (int) ceil($totalSectors / 128);
            if ($requiredFat === $fatSectorCount) {
                break;
            }
            $fatSectorCount = $requiredFat;
        }

        $fatSectorIndices = [];
        for ($i = 0; $i < $fatSectorCount; $i++) {
            $fatSectorIndices[] = count($this->sectors);
            $this->sectors[] = str_repeat("\0", self::SECTOR_SIZE);
        }

        $totalSectors = count($this->sectors);
        $fatEntries = array_fill(0, $totalSectors, self::FREE_SECTOR);

        foreach ($this->sectorChains as $chain) {
            if ($chain === []) {
                continue;
            }

            for ($i = 0; $i < count($chain); $i++) {
                $sector = $chain[$i];
                $fatEntries[$sector] = $chain[$i + 1] ?? self::END_OF_CHAIN;
            }
        }

        foreach ($directoryChain as $i => $sector) {
            $fatEntries[$sector] = $directoryChain[$i + 1] ?? self::END_OF_CHAIN;
        }

        foreach ($fatSectorIndices as $sector) {
            $fatEntries[$sector] = self::FAT_SECTOR;
        }

        $this->writeFatSectors($fatSectorIndices, $fatEntries);

        $header = $this->buildHeader(
            $fatSectorIndices,
            $directoryStart,
            $fatSectorCount,
            $this->miniFatStart,
            $this->miniFatSectorCount
        );

        return $header . implode('', $this->sectors);
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
        $nameA = $this->entries[$a]->name;
        $nameB = $this->entries[$b]->name;

        $lenA = strlen($nameA);
        $lenB = strlen($nameB);

        if ($lenA < $lenB) {
            return -1;
        }

        if ($lenA > $lenB) {
            return 1;
        }

        return strcasecmp($nameA, $nameB);
    }

    private function allocateStreams(): void
    {
        $miniStreamData = '';
        $miniChains = [];
        $totalMiniSectors = 0;

        foreach ($this->streamData as $index => $data) {
            $length = strlen($data);
            if ($length === 0) {
                $this->entries[$index]->startingSector = self::NO_STREAM;
                $this->entries[$index]->streamSize = BigInteger::zero();
                continue;
            }

            if ($length < 4096) {
                $chain = [];
                $offset = 0;
                while ($offset < $length) {
                    $chunk = substr($data, $offset, 64);
                    $chunk = str_pad($chunk, 64, "\0");
                    $miniStreamData .= $chunk;
                    $chain[] = $totalMiniSectors;
                    $totalMiniSectors++;
                    $offset += 64;
                }

                $this->entries[$index]->startingSector = $chain[0] ?? self::NO_STREAM;
                $this->entries[$index]->streamSize = BigInteger::of($length);
                $miniChains[$index] = $chain;
            } else {
                $start = $this->appendStreamSectors($data);
                $this->entries[$index]->startingSector = $start;
                $this->entries[$index]->streamSize = BigInteger::of($length);
            }
        }

        if ($totalMiniSectors > 0) {
            $miniFatEntries = array_fill(0, $totalMiniSectors, self::END_OF_CHAIN);
            foreach ($miniChains as $chain) {
                foreach ($chain as $i => $sector) {
                    $miniFatEntries[$sector] = $chain[$i + 1] ?? self::END_OF_CHAIN;
                }
            }

            $miniStreamStart = $this->appendStreamSectors($miniStreamData);
            $this->entries[0]->startingSector = $miniStreamStart;
            $this->entries[0]->streamSize = BigInteger::of(strlen($miniStreamData));

            $entriesPerSector = intdiv(self::SECTOR_SIZE, 4);
            $padding = $entriesPerSector - (count($miniFatEntries) % $entriesPerSector);
            if ($padding !== $entriesPerSector) {
                for ($i = 0; $i < $padding; $i++) {
                    $miniFatEntries[] = self::FREE_SECTOR;
                }
            }

            $miniFatData = pack('V' . count($miniFatEntries), ...$miniFatEntries);
            $miniFatStart = $this->appendStreamSectors($miniFatData);
            $this->miniFatStart = $miniFatStart;
            $this->miniFatSectorCount = count($this->sectorChains[$miniFatStart] ?? []);
        } else {
            $this->entries[0]->startingSector = self::NO_STREAM;
            $this->entries[0]->streamSize = BigInteger::zero();
            $this->miniFatStart = null;
            $this->miniFatSectorCount = 0;
        }
    }

    private function appendStreamSectors(string $data): int
    {
        $length = strlen($data);
        if ($length === 0) {
            return self::NO_STREAM;
        }

        $start = count($this->sectors);
        $chain = [];

        $offset = 0;
        while ($offset < $length) {
            $chunk = substr($data, $offset, self::SECTOR_SIZE);
            $chunk = str_pad($chunk, self::SECTOR_SIZE, "\0");
            $this->sectors[] = $chunk;
            $chain[] = count($this->sectors) - 1;
            $offset += self::SECTOR_SIZE;
        }

        $this->sectorChains[$start] = $chain;

        return $chain[0] ?? self::NO_STREAM;
    }

    private function buildDirectoryStream(): string
    {
        $buffer = '';
        foreach ($this->entries as $entry) {
            $buffer .= $entry->serialize();
        }

        $remainder = strlen($buffer) % self::SECTOR_SIZE;
        if ($remainder !== 0) {
            $buffer = str_pad($buffer, strlen($buffer) + (self::SECTOR_SIZE - $remainder), "\0");
        }

        return $buffer;
    }

    /**
     * @param list<int> $fatSectorIndices
     * @param list<int> $fatEntries
     */
    private function writeFatSectors(array $fatSectorIndices, array $fatEntries): void
    {
        $entriesPerSector = intdiv(self::SECTOR_SIZE, 4);
        $totalEntries = count($fatEntries);

        for ($i = 0; $i < count($fatSectorIndices); $i++) {
            $sectorIndex = $fatSectorIndices[$i];
            $start = $i * $entriesPerSector;
            $slice = array_slice($fatEntries, $start, $entriesPerSector);
            $slice = array_pad($slice, $entriesPerSector, self::FREE_SECTOR);
            $this->sectors[$sectorIndex] = pack('V' . $entriesPerSector, ...$slice);
        }
    }

    /**
     * @param list<int> $fatSectorIndices
     */
    private function buildHeader(
        array $fatSectorIndices,
        int $directoryStart,
        int $fatSectorCount,
        ?int $miniFatStart,
        int $miniFatSectorCount
    ): string
    {
        $signature = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        $minorVersion = pack('v', 0x003E);
        $majorVersion = pack('v', 0x0003);
        $byteOrder = pack('v', 0xFFFE);
        $sectorShift = pack('v', 9);
        $miniSectorShift = pack('v', 6);
        $reserved = str_repeat("\0", 6);
        $numberOfDirectorySectors = pack('V', 0);
        $numberOfFatSectors = pack('V', $fatSectorCount);
        $firstDirSectorLocation = pack('V', $directoryStart);
        $transactionSignatureNumber = pack('V', 0);
        $miniStreamCutOffSize = pack('V', 4096);
        $firstMiniFatSectorLocation = pack('V', $miniFatStart ?? self::NO_STREAM);
        $numberOfMiniFatSectors = pack('V', $miniFatSectorCount);
        $firstDifatSectorLocation = pack('V', self::NO_STREAM);
        $numberOfDifatSectors = pack('V', 0);

        $difatEntries = array_fill(0, 109, self::FREE_SECTOR);
        foreach ($fatSectorIndices as $i => $sector) {
            if ($i < 109) {
                $difatEntries[$i] = $sector;
            }
        }

        $difat = pack('V109', ...$difatEntries);

        $header = $signature
            . str_repeat("\0", 16)
            . $minorVersion
            . $majorVersion
            . $byteOrder
            . $sectorShift
            . $miniSectorShift
            . $reserved
            . $numberOfDirectorySectors
            . $numberOfFatSectors
            . $firstDirSectorLocation
            . $transactionSignatureNumber
            . $miniStreamCutOffSize
            . $firstMiniFatSectorLocation
            . $numberOfMiniFatSectors
            . $firstDifatSectorLocation
            . $numberOfDifatSectors
            . $difat;

        return str_pad($header, self::SECTOR_SIZE, "\0");
    }
}

final class DirectoryEntryData
{
    public string $name;
    public ObjectType $type;
    public ColorFlag $color;
    public int $leftSiblingId = CompoundFileBuilder::NO_STREAM;
    public int $rightSiblingId = CompoundFileBuilder::NO_STREAM;
    public int $childId = CompoundFileBuilder::NO_STREAM;
    public int $startingSector = CompoundFileBuilder::NO_STREAM;
    public BigInteger $streamSize;

    public function __construct(
        string $name,
        ObjectType $type,
        ColorFlag $color
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->color = $color;
        $this->streamSize = BigInteger::zero();
    }

    public function serialize(): string
    {
        $utf16 = mb_convert_encoding($this->name . "\0", 'UTF-16LE', 'UTF-8');
        $rawLength = strlen($utf16);
        $utf16 = str_pad($utf16, 64, "\0");
        $nameLength = pack('v', min($rawLength, 64));

        $left = pack('V', $this->leftSiblingId);
        $right = pack('V', $this->rightSiblingId);
        $child = pack('V', $this->childId);

        $clsid = str_repeat("\0", 16);
        $stateBits = pack('V', 0);
        $creationTime = str_repeat("\0", 8);
        $modifiedTime = str_repeat("\0", 8);
        $startingSector = pack('V', $this->startingSector);

        $size = $this->streamSize->isLessThan(0)
            ? BigInteger::zero()
            : $this->streamSize;
        $low = $size->mod(1 << 32)->toInt();
        $high = $size->shiftedRight(32)->toInt();
        $streamSize = pack('V', $low) . pack('V', $high);

        $buffer = $utf16
            . $nameLength
            . chr($this->type->value)
            . chr($this->color->value)
            . $left
            . $right
            . $child
            . $clsid
            . $stateBits
            . $creationTime
            . $modifiedTime
            . $startingSector
            . $streamSize;

        return str_pad($buffer, 128, "\0");
    }
}

