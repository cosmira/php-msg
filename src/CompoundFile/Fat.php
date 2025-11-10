<?php

declare(strict_types=1);

namespace MsgViewer\CompoundFile;

use MsgViewer\IO\BinaryBuffer;

final class Fat
{
    /**
     * @return int[]
     */
    public static function collect(BinaryBuffer $buffer, Header $header, array $difat): array
    {
        $sectorSize = Util::fatSectorSize($header);
        $fat = [];

        foreach ($difat as $sector) {
            $offset = Util::sectorOffset($sector, $header->sectorSize);

            for ($i = 0; $i < $sectorSize; $i++) {
                $fat[] = $buffer->getUint32($offset);
                $offset += 4;
            }
        }

        return $fat;
    }
}
