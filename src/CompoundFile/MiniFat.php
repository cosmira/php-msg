<?php

declare(strict_types=1);

namespace MsgViewer\CompoundFile;

use MsgViewer\IO\BinaryBuffer;

final class MiniFat
{
    /**
     * @return int[]
     */
    public static function collect(BinaryBuffer $buffer, Header $header, array $fat): array
    {
        $sectorSize = Util::fatSectorSize($header);
        $miniFat = [];

        $sector = $header->firstMiniFatSectorLocation;
        while ($sector < 0xFFFFFFFE) {
            $offset = Util::sectorOffset($sector, $header->sectorSize);

            for ($i = 0; $i < $sectorSize; $i++) {
                $miniFat[] = $buffer->getUint32($offset);
                $offset += 4;
            }

            $sector = $fat[$sector] ?? 0xFFFFFFFE;
        }

        return $miniFat;
    }
}
