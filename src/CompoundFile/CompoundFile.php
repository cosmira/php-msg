<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\CompoundFile;

use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Cosmira\OutlookMessage\CompoundFile\Directory\Directory;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use RuntimeException;
use Stringable;

final readonly class CompoundFile implements Stringable
{
    /**
     * Create a parsed compound file representation.
     */
    public function __construct(
        /**
         * The immutable binary source for the compound file.
         */
        public BinaryBuffer $buffer,
        /**
         * The decoded compound-file header.
         */
        public Header $header,
        /**
         * The decoded DIFAT sector indexes.
         *
         * @var array<int, int>
         */
        public array $difat,
        /**
         * The decoded FAT sector chain.
         *
         * @var array<int, int>
         */
        public array $fat,
        /**
         * The decoded MiniFAT sector chain.
         *
         * @var array<int, int>
         */
        public array $miniFat,
        /**
         * The decoded compound-file directory.
         */
        public Directory $directory
    ) {}

    /**
     * Parse a compound file from the given binary buffer.
     */
    public static function fromBinary(BinaryBuffer $buffer): self
    {
        $header = Header::parse($buffer);
        $difat = Difat::collect($buffer, $header);
        $fat = Fat::collect($buffer, $header, $difat);
        $miniFat = MiniFat::collect($buffer, $header, $fat);
        $directory = Directory::load($buffer, $header, $fat);

        return new self($buffer, $header, $difat, $fat, $miniFat, $directory);
    }

    /**
     * Read a compound stream incrementally in bounded chunks.
     *
     * @param callable(int $offset, string $chunk): void $onChunk
     * @param callable(int $offset): int|null            $onHeader
     *
     * @throws MathException
     */
    public function readStream(
        DirectoryEntry $entry,
        callable $onChunk,
        ?int $blockSize = null,
        ?callable $onHeader = null
    ): void {
        $sector = $entry->startingSectorLocation;
        if ($sector >= 0xFFFFFFFE) {
            throw_unless($entry->streamSize->isZero(), CorruptedFileException::class, 'Non-empty stream has no allocated sector chain.');

            return;
        }

        $sectorSize = $this->header->sectorSize;
        $fat = $this->fat;

        if ($entry->streamSize->isLessThan($this->header->miniStreamCutOffSize)) {
            $sectorSize = $this->header->miniSectorSize;
            $fat = $this->miniFat;
        }

        $offset = Util::streamSectorOffset(
            $sector,
            $this->header,
            $entry->streamSize,
            $this->directory->miniStreamLocations
        );

        $positionInSector = 0;
        $tortoise = $sector;
        $hare = $sector;

        $headerSize = 0;
        if ($onHeader !== null) {
            $headerSize = $onHeader($offset);
        }

        throw_if($headerSize < 0 || $entry->streamSize->isLessThan($headerSize), CorruptedFileException::class, 'Stream header exceeds the declared stream size.');
        throw_if($headerSize > $sectorSize, CorruptedFileException::class, 'Stream header crosses a sector boundary.');

        $streamSize = $entry->streamSize->minus(BigInteger::of($headerSize));
        $offset += $headerSize;
        $positionInSector += $headerSize;

        if ($blockSize === null) {
            $blockSize = min($this->header->miniSectorSize, $sectorSize);
            $remaining = $streamSize->isLessThan($blockSize) ? $streamSize->toInt() : $blockSize;
            $blockSize = max(1, $remaining);
        }

        $blockSizeBig = BigInteger::of($blockSize);

        $hasRemainingBytes = $streamSize->isGreaterThan(BigInteger::zero());

        while ($hasRemainingBytes) {
            $crossedSectorBoundary = $positionInSector >= $sectorSize;

            if ($crossedSectorBoundary) {
                $sector = $fat[$sector] ?? 0xFFFFFFFE;
                throw_if($sector >= 0xFFFFFFFE, CorruptedFileException::class, 'Stream sector chain ended before the declared stream size was read.');

                $tortoise = $fat[$tortoise] ?? 0xFFFFFFFE;
                $hare = $fat[$hare] ?? 0xFFFFFFFE;
                if ($hare < 0xFFFFFFFE) {
                    $hare = $fat[$hare] ?? 0xFFFFFFFE;
                }

                throw_if($tortoise < 0xFFFFFFFE && $tortoise === $hare, CorruptedFileException::class, 'Circular reference detected in stream sector chain.');

                $offset = Util::streamSectorOffset(
                    $sector,
                    $this->header,
                    $entry->streamSize,
                    $this->directory->miniStreamLocations
                );
                $positionInSector = 0;
            }

            $remainingInSector = $sectorSize - $positionInSector;
            $bytes = min(
                $streamSize->isLessThan($blockSizeBig) ? $streamSize->toInt() : $blockSize,
                $remainingInSector,
            );

            $chunk = $this->buffer->slice($offset, $bytes);
            $onChunk($offset, $chunk);

            $streamSize = $streamSize->minus(BigInteger::of($bytes));
            $offset += $bytes;
            $positionInSector += $bytes;
            $hasRemainingBytes = $streamSize->isGreaterThan(BigInteger::zero());
        }
    }

    /**
     * Read the given compound stream into a bounded string.
     */
    public function readStreamToString(DirectoryEntry $entry, int $maxBytes = 100 * 1024 * 1024): string
    {
        if ($entry->streamSize->isGreaterThan($maxBytes)) {
            throw new CorruptedFileException(sprintf('Stream size exceeds maximum allowed (%d bytes).', $maxBytes));
        }

        $result = '';
        $this->readStream(
            $entry,
            static function (int $_, string $chunk) use (&$result): void {
                $result .= $chunk;
            }
        );

        return $result;
    }

    /**
     * Copy a compound stream into the given writable destination in bounded chunks.
     *
     * @param resource $destination
     */
    public function copyStreamTo(DirectoryEntry $entry, $destination): void
    {
        throw_unless(is_resource($destination), RuntimeException::class, 'Compound stream destination must be writable.');

        if ($entry->streamSize->isGreaterThanOrEqualTo($this->header->miniStreamCutOffSize)) {
            $this->copyRegularStreamTo($entry, $destination);

            return;
        }

        $this->readStream($entry, static function (int $_offset, string $chunk) use ($destination): void {
            self::writeChunk($destination, $chunk);
        }, 1024 * 1024);
    }

    /**
     * Copy a regular FAT stream while coalescing physically consecutive sectors.
     *
     * @param resource $destination
     */
    private function copyRegularStreamTo(DirectoryEntry $entry, $destination): void
    {
        $remaining = $entry->streamSize->toInt();
        if ($remaining === 0) {
            return;
        }

        $current = $entry->startingSectorLocation;
        throw_if($current >= 0xFFFFFFFE, CorruptedFileException::class, 'Non-empty stream has no allocated sector chain.');
        $tortoise = $current;
        $hare = $current;
        $maximumRunSectors = intdiv(1024 * 1024, $this->header->sectorSize);

        while ($remaining > 0) {
            $runStart = $current;
            $runEnd = $current;
            $runSectors = 1;
            $nextCurrent = null;

            while ($runSectors < $maximumRunSectors && $runSectors * $this->header->sectorSize < $remaining) {
                $next = $this->fat[$runEnd] ?? 0xFFFFFFFE;
                throw_if($next >= 0xFFFFFFFE, CorruptedFileException::class, 'Stream sector chain ended before the declared stream size was read.');
                $this->advanceCyclePointers($tortoise, $hare, $this->fat);

                if ($next !== $runEnd + 1) {
                    $nextCurrent = $next;

                    break;
                }

                $runEnd = $next;
                $runSectors++;
            }

            $bytes = min($remaining, $runSectors * $this->header->sectorSize);
            $offset = ($runStart + 1) * $this->header->sectorSize;
            self::writeChunk($destination, $this->buffer->slice($offset, $bytes));
            $remaining -= $bytes;

            if ($remaining === 0) {
                return;
            }

            if ($nextCurrent === null) {
                $nextCurrent = $this->fat[$runEnd] ?? 0xFFFFFFFE;
                throw_if($nextCurrent >= 0xFFFFFFFE, CorruptedFileException::class, 'Stream sector chain ended before the declared stream size was read.');
                $this->advanceCyclePointers($tortoise, $hare, $this->fat);
            }

            $current = $nextCurrent;
        }
    }

    /**
     * Advance constant-memory cycle detection for one FAT chain edge.
     *
     * @param array<int, int> $fat
     */
    private function advanceCyclePointers(int &$tortoise, int &$hare, array $fat): void
    {
        $tortoise = $fat[$tortoise] ?? 0xFFFFFFFE;
        $hare = $fat[$hare] ?? 0xFFFFFFFE;
        if ($hare < 0xFFFFFFFE) {
            $hare = $fat[$hare] ?? 0xFFFFFFFE;
        }

        throw_if($tortoise < 0xFFFFFFFE && $tortoise === $hare, CorruptedFileException::class, 'Circular reference detected in stream sector chain.');
    }

    /**
     * Write every byte of a stream chunk to the destination.
     *
     * @param resource $destination
     */
    private static function writeChunk($destination, string $chunk): void
    {
        $written = 0;
        while ($written < strlen($chunk)) {
            $bytes = fwrite($destination, substr($chunk, $written));
            throw_if($bytes === false || $bytes === 0, RuntimeException::class, 'Unable to copy compound stream data.');
            $written += $bytes;
        }
    }

    /**
     * Convert the compound file metadata to its JSON representation.
     */
    public function __toString(): string
    {
        return json_encode([
            'header'    => $this->header,
            'difat'     => $this->difat,
            'fat'       => $this->fat,
            'miniFat'   => $this->miniFat,
            'directory' => $this->directory,
        ], JSON_THROW_ON_ERROR);
    }
}
