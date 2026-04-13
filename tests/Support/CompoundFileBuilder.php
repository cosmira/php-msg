<?php

declare(strict_types=1);

namespace MsgViewer\Tests\Support;

use MsgViewer\CompoundFile\Header;

final class CompoundFileBuilder
{
    public static function createHeaderBinary(
        int $byteOrder = 0xFFFE,
        int $sectorShift = 9,
        int $miniSectorShift = 6,
        int $miniStreamCutOffSize = 4096,
    ): string {
        $signature = Header::SIGNATURE;
        $minorVersion = pack('v', 0x003E);
        $majorVersion = pack('v', 0x0003);
        $byteOrderBytes = pack('v', $byteOrder);
        $sectorShiftBytes = pack('v', $sectorShift);
        $miniSectorShiftBytes = pack('v', $miniSectorShift);

        $reserved = str_repeat("\0", 6);
        $numberOfDirectorySectors = pack('V', 0);
        $numberOfFatSectors = pack('V', 1);
        $firstDirSectorLocation = pack('V', 0);
        $transactionSignatureNumber = pack('V', 0);
        $miniStreamCutOffSizeBytes = pack('V', $miniStreamCutOffSize);
        $firstMiniFatSectorLocation = pack('V', 0xFFFFFFFF);
        $numberOfMiniFatSectors = pack('V', 0);
        $firstDifatSectorLocation = pack('V', 0xFFFFFFFE);
        $numberOfDifatSectors = pack('V', 0);

        $difat = pack('V', 2).pack('V', 0xFFFFFFFF).str_repeat("\0", 436 - 8);

        $binary = $signature
            .str_repeat("\0", 16)
            .$minorVersion
            .$majorVersion
            .$byteOrderBytes
            .$sectorShiftBytes
            .$miniSectorShiftBytes
            .$reserved
            .$numberOfDirectorySectors
            .$numberOfFatSectors
            .$firstDirSectorLocation
            .$transactionSignatureNumber
            .$miniStreamCutOffSizeBytes
            .$firstMiniFatSectorLocation
            .$numberOfMiniFatSectors
            .$firstDifatSectorLocation
            .$numberOfDifatSectors
            .$difat;

        return str_pad($binary, 512, "\0");
    }
}
