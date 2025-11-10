<?php

declare(strict_types=1);

namespace MsgViewer\CompoundFile;

use MsgViewer\IO\BinaryBuffer;

final class Difat
{
    /**
     * @return int[]
     */
    public static function collect(BinaryBuffer $buffer, Header $header): array
    {
        $result = $header->difat;
        $fatNumber = $header->majorVersion === 3 ? 127 : 1023;

        $sector = $header->firstDifatSectorLocation;
        while ($sector < 0xFFFFFFFE) {
            $offset = Util::sectorOffset($sector, $header->sectorSize);

            for ($i = 0; $i < $fatNumber; $i++) {
                $value = $buffer->getUint32($offset);
                if ($value === 0xFFFFFFFF) {
                    $offset += ($fatNumber - $i) * 4;
                    break;
                }

                $result[] = $value;
                $offset += 4;
            }

            $sector = $buffer->getUint32($offset);
        }

        return $result;
    }
}
