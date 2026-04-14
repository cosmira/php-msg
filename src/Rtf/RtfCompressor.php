<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Rtf;

final class RtfCompressor
{
    private const MAGIC_UNCOMPRESSED_RTF = 0x414C454D;

    public static function wrapUncompressed(string $rtf): string
    {
        $rawSize = strlen($rtf);
        $compSize = $rawSize + 12;

        return pack('V', $compSize)
            .pack('V', $rawSize)
            .pack('V', self::MAGIC_UNCOMPRESSED_RTF)
            .pack('V', 0)
            .$rtf;
    }
}
