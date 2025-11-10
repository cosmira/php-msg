<?php

declare(strict_types=1);

namespace MsgViewer\CompoundFile;

use MsgViewer\IO\BinaryBuffer;
use RuntimeException;

final class Header
{
    public const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /**
     * @param int[] $signature
     * @param int[] $difat
     */
    private function __construct(
        public readonly array $signature,
        public readonly int $minorVersion,
        public readonly int $majorVersion,
        public readonly int $byteOrder,
        public readonly int $sectorSize,
        public readonly int $miniSectorSize,
        public readonly int $numberOfDirectorySectors,
        public readonly int $numberOfFatSectors,
        public readonly int $firstDirSectorLocation,
        public readonly int $transactionSignatureNumber,
        public readonly int $miniStreamCutOffSize,
        public readonly int $firstMiniFatSectorLocation,
        public readonly int $numberOfMiniFatSectors,
        public readonly int $firstDifatSectorLocation,
        public readonly int $numberOfDifatSectors,
        public readonly array $difat
    ) {}

    public static function parse(BinaryBuffer $buffer): self
    {
        $signature = $buffer->slice(0, 8);
        if ($signature !== self::SIGNATURE) {
            throw new RuntimeException('Signature mismatch!');
        }

        $offset = 8;

        // Header CLSID (16 bytes) ignored
        $offset += 16;

        $minorVersion = $buffer->getUint16($offset);
        $offset += 2;

        $majorVersion = $buffer->getUint16($offset);
        $offset += 2;

        $byteOrder = $buffer->getUint16($offset);
        $offset += 2;

        $sectorSize = 2 ** $buffer->getUint16($offset);
        $offset += 2;

        $miniSectorSize = 2 ** $buffer->getUint16($offset);
        $offset += 2;

        // Reserved (6 bytes)
        $offset += 6;

        $numberOfDirectorySectors = $buffer->getUint32($offset);
        $offset += 4;

        $numberOfFatSectors = $buffer->getUint32($offset);
        $offset += 4;

        $firstDirSectorLocation = $buffer->getUint32($offset);
        $offset += 4;

        $transactionSignatureNumber = $buffer->getUint32($offset);
        $offset += 4;

        $miniStreamCutOffSize = $buffer->getUint32($offset);
        $offset += 4;

        $firstMiniFatSectorLocation = $buffer->getUint32($offset);
        $offset += 4;

        $numberOfMiniFatSectors = $buffer->getUint32($offset);
        $offset += 4;

        $firstDifatSectorLocation = $buffer->getUint32($offset);
        $offset += 4;

        $numberOfDifatSectors = $buffer->getUint32($offset);
        $offset += 4;

        $difat = [];
        for ($i = 0; $i < 436; $i += 4) {
            $value = $buffer->getUint32($offset);
            if ($value === 0xFFFFFFFF) {
                $offset += (436 - $i);
                break;
            }

            $difat[] = $value;
            $offset += 4;
        }

        return new self(
            array_map(static fn (string $byte): int => ord($byte), str_split(self::SIGNATURE)),
            $minorVersion,
            $majorVersion,
            $byteOrder,
            $sectorSize,
            $miniSectorSize,
            $numberOfDirectorySectors,
            $numberOfFatSectors,
            $firstDirSectorLocation,
            $transactionSignatureNumber,
            $miniStreamCutOffSize,
            $firstMiniFatSectorLocation,
            $numberOfMiniFatSectors,
            $firstDifatSectorLocation,
            $numberOfDifatSectors,
            $difat
        );
    }
}
