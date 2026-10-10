<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Rtf;

use Cosmira\OutlookMessage\Mapi\Properties;

/**
 * Decodes RTF \'hh bytes from a Windows codepage to UTF-8.
 *
 * @internal
 */
final class RtfCodepage
{
    /**
     * Windows codepages of the RTF \fcharset values; DEFAULT_CHARSET and SYMBOL_CHARSET
     * are absent because they follow the document codepage (\ansicpg).
     */
    private const CHARSET_CODEPAGES = [
        0   => 1252,
        77  => 10000,
        128 => 932,
        129 => 949,
        130 => 1361,
        134 => 936,
        136 => 950,
        161 => 1253,
        162 => 1254,
        163 => 1258,
        177 => 1255,
        178 => 1256,
        186 => 1257,
        204 => 1251,
        222 => 874,
        238 => 1250,
        254 => 437,
        255 => 850,
    ];

    /**
     * @var array<int, string|null>
     */
    private static array $mbEncodings = [];

    /**
     * Get the codepage of an RTF \fcharset value, or null when the document codepage applies.
     */
    public static function forCharset(int $charset): ?int
    {
        return self::CHARSET_CODEPAGES[$charset] ?? null;
    }

    /**
     * Convert bytes of the given Windows codepage to UTF-8.
     *
     * mbstring lacks several Windows codepages (1250, 1253, 1255-1258, ...),
     * so iconv is used for those before falling back to Windows-1252.
     */
    public static function decode(string $bytes, int $codepage): string
    {
        if ($codepage === 65001) {
            return mb_scrub($bytes, 'UTF-8');
        }

        $encoding = self::mbEncoding($codepage);
        $decoded = $encoding !== null ? mb_convert_encoding($bytes, 'UTF-8', $encoding) : false;

        if (! is_string($decoded) && function_exists('iconv')) {
            $decoded = @iconv('CP'.$codepage, 'UTF-8//IGNORE', $bytes);
        }

        return is_string($decoded)
            ? $decoded
            : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    private static function mbEncoding(int $codepage): ?string
    {
        if (array_key_exists($codepage, self::$mbEncodings)) {
            return self::$mbEncodings[$codepage];
        }

        Properties::init();

        $name = Properties::$codepages[$codepage] ?? null;
        $supported = array_map(strtolower(...), mb_list_encodings());

        return self::$mbEncodings[$codepage] = $name !== null && in_array(strtolower($name), $supported, true)
            ? $name
            : null;
    }
}
