<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\CompoundFile;

use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Cosmira\OutlookMessage\CompoundFile\Directory\Directory;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Stringable;

final readonly class CompoundFile implements Stringable
{
    public function __construct(
        public BinaryBuffer $buffer,
        public Header $header,
        /** @var array<int, int> */
        public array $difat,
        /** @var array<int, int> */
        public array $fat,
        /** @var array<int, int> */
        public array $miniFat,
        public Directory $directory
    ) {}

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
     * @param DirectoryEntry                             $entry
     * @param callable(int $offset, string $chunk): void $onChunk
     * @param int|null                                   $blockSize
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

        $initialOffset = $offset;

        $headerSize = 0;
        if ($onHeader !== null) {
            $headerSize = $onHeader($offset);
        }

        $streamSize = $entry->streamSize->minus(BigInteger::of($headerSize));
        $offset += $headerSize;

        if ($blockSize === null) {
            $blockSize = min($this->header->miniSectorSize, $sectorSize);
            $remaining = $streamSize->isLessThan($blockSize) ? $streamSize->toInt() : $blockSize;
            $blockSize = max(1, $remaining);
        }

        $blockSizeBig = BigInteger::of($blockSize);

        while ($streamSize->isGreaterThan(BigInteger::zero())) {
            if (($offset - $initialOffset) >= $sectorSize) {
                $sector = $fat[$sector] ?? 0xFFFFFFFE;
                if ($sector >= 0xFFFFFFFE) {
                    break;
                }

                $offset = Util::streamSectorOffset(
                    $sector,
                    $this->header,
                    $entry->streamSize,
                    $this->directory->miniStreamLocations
                );
                $initialOffset = $offset;
            }

            $bytes = $streamSize->isLessThan($blockSizeBig)
                ? $streamSize->toInt()
                : $blockSize;

            $chunk = $this->buffer->slice($offset, $bytes);
            $onChunk($offset, $chunk);

            $streamSize = $streamSize->minus(BigInteger::of($bytes));
            $offset += $bytes;
        }
    }

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
