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
     * Canonical truncated chain used by this operation.
     */
    private const TRUNCATED_CHAIN = 'Stream sector chain ended before the declared '
        .'stream size was read.';

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
        public array|PackedFatTable $fat,
        /**
         * The decoded MiniFAT sector chain.
         *
         * @var array<int, int>
         */
        public array|PackedFatTable $miniFat,
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
            throw_unless(
                $entry->streamSize->isZero(),
                CorruptedFileException::class,
                'Non-empty stream has no allocated sector chain.',
            );

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

        $headerSize = $this->streamHeaderSize($entry, $offset, $sectorSize, $onHeader);
        $streamSize = $entry->streamSize->minus(BigInteger::of($headerSize));
        $chunkSize = $blockSize ?? $this->defaultBlockSize($streamSize, $sectorSize);
        $this->readPayloadChunks(
            $entry,
            $onChunk,
            $chunkSize,
            $sectorSize,
            $fat,
            $offset + $headerSize,
            $headerSize,
            $streamSize,
        );
    }

    /**
     * Validate the optional stream header against its declared size and first sector.
     *
     * @param callable(int): int|null $onHeader
     */
    private function streamHeaderSize(
        DirectoryEntry $entry,
        int $offset,
        int $sectorSize,
        ?callable $onHeader,
    ): int {
        $headerSize = $onHeader === null ? 0 : $onHeader($offset);
        throw_if(
            $headerSize < 0 || $entry->streamSize->isLessThan($headerSize),
            CorruptedFileException::class,
            'Stream header exceeds the declared stream size.',
        );
        throw_if(
            $headerSize > $sectorSize,
            CorruptedFileException::class,
            'Stream header crosses a sector boundary.',
        );

        return $headerSize;
    }

    /**
     * Choose a nonzero default chunk bounded by the stream and sector sizes.
     */
    private function defaultBlockSize(BigInteger $streamSize, int $sectorSize): int
    {
        $blockSize = min($this->header->miniSectorSize, $sectorSize);
        $remaining = $streamSize->isLessThan($blockSize) ? $streamSize->toInt() : $blockSize;

        return max(1, $remaining);
    }

    /**
     * Deliver the payload chunks while traversing and validating its sector chain.
     *
     * @param callable(int, string): void    $onChunk
     * @param array<int, int>|PackedFatTable $fat
     */
    private function readPayloadChunks(
        DirectoryEntry $entry,
        callable $onChunk,
        int $blockSize,
        int $sectorSize,
        array|PackedFatTable $fat,
        int $offset,
        int $headerSize,
        BigInteger $streamSize,
    ): void {
        $cursorOffset = $offset;
        $remainingSize = $streamSize;
        $sector = $entry->startingSectorLocation;
        $positionInSector = $headerSize;
        $tortoise = $sector;
        $hare = $sector;
        $blockSizeBig = BigInteger::of($blockSize);
        while ($remainingSize->isGreaterThan(0)) {
            $crossedSector = $positionInSector >= $sectorSize;

            if ($crossedSector) {
                $sector = $this->nextStreamSector($sector, $tortoise, $hare, $fat);
                $cursorOffset = Util::streamSectorOffset(
                    $sector,
                    $this->header,
                    $entry->streamSize,
                    $this->directory->miniStreamLocations
                );
                $positionInSector = 0;
            }

            $sectorRemaining = $sectorSize - $positionInSector;
            $bytes = min(
                $remainingSize->isLessThan($blockSizeBig) ? $remainingSize->toInt() : $blockSize,
                $sectorRemaining,
            );

            $chunk = $this->buffer->slice($cursorOffset, $bytes);
            $onChunk($cursorOffset, $chunk);

            $remainingSize = $remainingSize->minus(BigInteger::of($bytes));
            $cursorOffset += $bytes;
            $positionInSector += $bytes;
        }
    }

    /**
     * Advance one chain edge and reject premature ends or circular references.
     *
     * @param array<int, int>|PackedFatTable $fat
     */
    private function nextStreamSector(
        int $sector,
        int &$tortoise,
        int &$hare,
        array|PackedFatTable $fat,
    ): int {
        $next = $fat[$sector] ?? 0xFFFFFFFE;
        throw_if($next >= 0xFFFFFFFE, CorruptedFileException::class, self::TRUNCATED_CHAIN);
        $this->advanceCyclePointers($tortoise, $hare, $fat);

        return $next;
    }

    /**
     * Read the given compound stream into a bounded string.
     */
    public function readStreamToString(
        DirectoryEntry $entry,
        int $maxBytes = 100 * 1024 * 1024,
    ): string {
        if ($entry->streamSize->isGreaterThan($maxBytes)) {
            throw new CorruptedFileException(sprintf(
                'Stream size exceeds maximum allowed (%d bytes).',
                $maxBytes,
            ));
        }

        $result = '';
        $this->readStream(
            $entry,
            static function (int $ignored, string $chunk) use (&$result): void {
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
    public function copyStreamTo(DirectoryEntry $entry, mixed $destination): void
    {
        throw_unless(
            is_resource($destination),
            RuntimeException::class,
            'Compound stream destination must be writable.',
        );

        if ($entry->streamSize->isGreaterThanOrEqualTo($this->header->miniStreamCutOffSize)) {
            $this->copyRegularStreamTo($entry, $destination);

            return;
        }

        $this->readStream(
            $entry,
            static function (int $_offset, string $chunk) use ($destination): void {
                self::writeChunk($destination, $chunk);
            },
            1024 * 1024,
        );
    }

    /**
     * Copy a regular FAT stream while coalescing physically consecutive sectors.
     *
     * @param resource $destination
     */
    private function copyRegularStreamTo(DirectoryEntry $entry, mixed $destination): void
    {
        $remaining = $entry->streamSize->toInt();
        if ($remaining === 0) {
            return;
        }

        $current = $entry->startingSectorLocation;
        throw_if(
            $current >= 0xFFFFFFFE,
            CorruptedFileException::class,
            'Non-empty stream has no allocated sector chain.',
        );
        $tortoise = $current;
        $hare = $current;
        $maxRunSectors = intdiv(1024 * 1024, $this->header->sectorSize);

        while ($remaining > 0) {
            $runStart = $current;
            [$runEnd, $runSectors, $nextCurrent] = $this->contiguousRun(
                $current,
                $remaining,
                $maxRunSectors,
                $tortoise,
                $hare,
            );

            $bytes = min($remaining, $runSectors * $this->header->sectorSize);
            $offset = ($runStart + 1) * $this->header->sectorSize;
            self::writeChunk($destination, $this->buffer->slice($offset, $bytes));
            $remaining -= $bytes;

            if ($remaining === 0) {
                return;
            }

            $current = $nextCurrent ?? $this->nextStreamSector(
                $runEnd,
                $tortoise,
                $hare,
                $this->fat,
            );
        }
    }

    /**
     * Discover a bounded run of adjacent regular sectors without consuming its successor.
     *
     * @return array{int, int, int|null}
     */
    private function contiguousRun(
        int $current,
        int $remaining,
        int $maxRunSectors,
        int &$tortoise,
        int &$hare,
    ): array {
        $runEnd = $current;
        $runSectors = 1;
        $nextCurrent = null;

        $sectorCount = intdiv(
            $remaining + $this->header->sectorSize - 1,
            $this->header->sectorSize,
        );
        $runLimit = min($maxRunSectors, $sectorCount);
        while ($runSectors < $runLimit) {
            $next = $this->fat[$runEnd] ?? 0xFFFFFFFE;
            throw_if(
                $next >= 0xFFFFFFFE,
                CorruptedFileException::class,
                self::TRUNCATED_CHAIN,
            );
            $this->advanceCyclePointers($tortoise, $hare, $this->fat);

            $adjacentSector = $runEnd + 1;
            if ($next !== $adjacentSector) {
                $nextCurrent = $next;

                break;
            }

            $runEnd = $next;
            $runSectors++;
        }

        return [$runEnd, $runSectors, $nextCurrent];
    }

    /**
     * Advance constant-memory cycle detection for one FAT chain edge.
     *
     * @param array<int, int>|PackedFatTable $fat
     */
    private function advanceCyclePointers(
        int &$tortoise,
        int &$hare,
        array|PackedFatTable $fat,
    ): void {
        $tortoise = $fat[$tortoise] ?? 0xFFFFFFFE;
        $hare = $fat[$hare] ?? 0xFFFFFFFE;
        if ($hare < 0xFFFFFFFE) {
            $hare = $fat[$hare] ?? 0xFFFFFFFE;
        }

        throw_if(
            $tortoise < 0xFFFFFFFE && $tortoise === $hare,
            CorruptedFileException::class,
            'Circular reference detected in stream sector chain.',
        );
    }

    /**
     * Write every byte of a stream chunk to the destination.
     *
     * @param resource $destination
     */
    private static function writeChunk(mixed $destination, string $chunk): void
    {
        $written = 0;
        while ($written < strlen($chunk)) {
            $bytes = fwrite($destination, substr($chunk, $written, 1048576));
            throw_if(
                $bytes === false || $bytes === 0,
                RuntimeException::class,
                'Unable to copy compound stream data.',
            );
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
