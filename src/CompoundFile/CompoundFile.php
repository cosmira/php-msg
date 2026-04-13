<?php

declare(strict_types=1);

namespace MsgViewer\CompoundFile;

use Brick\Math\BigInteger;
use MsgViewer\CompoundFile\Directory\Directory;
use MsgViewer\CompoundFile\Directory\DirectoryEntry;
use MsgViewer\Support\BinaryBuffer;
use Stringable;

final class CompoundFile implements Stringable
{
    public function __construct(
        public readonly BinaryBuffer $buffer,
        public readonly Header $header,
        /** @var int[] */
        public readonly array $difat,
        /** @var int[] */
        public readonly array $fat,
        /** @var int[] */
        public readonly array $miniFat,
        public readonly Directory $directory
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
     * @param callable(int $offset, string $chunk): void $onChunk
     * @param callable(int $offset): int|null            $onHeader
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
            $headerSize = (int) ($onHeader($offset) ?? 0);
        }

        $streamSize = $entry->streamSize->minus(BigInteger::of($headerSize));
        $offset += $headerSize;

        if ($blockSize === null) {
            $blockSize = min($this->header->miniSectorSize, $sectorSize);
            $remaining = $streamSize->isLessThan($blockSize) ? (int) $streamSize->toInt() : $blockSize;
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

    public function readStreamToString(DirectoryEntry $entry): string
    {
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
