<?php

declare(strict_types=1);

namespace MsgViewer\CompoundFile;

use Brick\Math\BigInteger;

final class Util
{
    public static function sectorOffset(int $sector, int $sectorSize): int
    {
        return ($sector + 1) * $sectorSize;
    }

    public static function streamSectorOffset(int $sector, Header $header, BigInteger $streamSize, array $miniStreamLocations): int
    {
        $offset = self::sectorOffset($sector, $header->sectorSize);

        if ($streamSize->isLessThan($header->miniStreamCutOffSize)) {
            $offset = $sector * $header->miniSectorSize;
            $miniStreamSector = intdiv($offset, $header->sectorSize);
            $miniStreamOffset = $offset % $header->sectorSize;

            $miniSector = $miniStreamLocations[$miniStreamSector] ?? null;
            if ($miniSector === null) {
                return $offset;
            }

            $offset = self::sectorOffset($miniSector, $header->sectorSize) + $miniStreamOffset;
        }

        return $offset;
    }

    public static function fatSectorSize(Header $header): int
    {
        return $header->majorVersion === 3 ? 128 : 1024;
    }
}

