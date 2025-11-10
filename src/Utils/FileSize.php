<?php

declare(strict_types=1);

namespace MsgViewer\Utils;

final class FileSize
{
    private const SIZE = 1000;
    private const UNITS = ['bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

    public static function bytesWithUnits(int $bytes): string
    {
        $unit = 0;
        $value = (float) $bytes;

        while ($value >= self::SIZE && $unit < count(self::UNITS) - 1) {
            $value /= self::SIZE;
            $unit++;
        }

        $precision = ($value < 10 && $unit > 0) ? 1 : 0;

        return sprintf('%s %s', number_format($value, $precision), self::UNITS[$unit]);
    }
}
