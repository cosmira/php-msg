<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Rtf\Decompressor;

final class Dictionary
{
    /**
     * The initial dictionary text prescribed by the compressed RTF format.
     */
    private const SEED = '{\\rtf1\\ansi\\mac\\deff0\\deftab720{\\fonttbl;}{\\f0\\fnil '
        .'\\froman \\fswiss '
        .'\\fmodern \\fscript \\fdecor MS Sans SerifSymbolArialTimes New RomanCourier'
        ."{\\colortbl\\red0\\green0\\blue0\r\n\\par \\pard\\plain\\f0\\fs20\\b\\i\\u\\tab\\tx";

    /**
     * The cached initial dictionary bytes.
     *
     * @var array<int, int>|null
     */
    private static ?array $cache = null;

    /**
     * Get a fresh copy of the initial RTF compression dictionary.
     *
     * @return array<int, int>
     */
    public static function seed(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $dict = array_fill(0, 4096, 0);
        $length = strlen(self::SEED);
        for ($index = 0; $index < $length; $index++) {
            $dict[$index] = ord(self::SEED[$index]);
        }

        self::$cache = $dict;

        return $dict;
    }

    /**
     * Get the number of bytes occupied by the seeded dictionary.
     */
    public static function seedLength(): int
    {
        return strlen(self::SEED);
    }
}
