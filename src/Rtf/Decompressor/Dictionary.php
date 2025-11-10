<?php

declare(strict_types=1);

namespace MsgViewer\Rtf\Decompressor;

final class Dictionary
{
    private const SEED = "{\\rtf1\\ansi\\mac\\deff0\\deftab720{\\fonttbl;}{\\f0\\fnil \\froman \\fswiss \\fmodern \\fscript \\fdecor MS Sans SerifSymbolArialTimes New RomanCourier{\\colortbl\\red0\\green0\\blue0\r\n\\par \\pard\\plain\\f0\\fs20\\b\\i\\u\\tab\\tx";

    /** @var int[]|null */
    private static ?array $cache = null;

    /**
     * @return int[]
     */
    public static function seed(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $dict = array_fill(0, 4096, 0);
        $length = strlen(self::SEED);
        for ($i = 0; $i < $length; $i++) {
            $dict[$i] = ord(self::SEED[$i]);
        }

        self::$cache = $dict;

        return $dict;
    }
}
