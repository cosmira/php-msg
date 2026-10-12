<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi\Decoding;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\Mapi\Properties;
use DateTimeImmutable;
use DateTimeZone;

/** @internal */
final class ValueDecoder
{
    /**
     * Encoding used for decoded message text.
     */
    private const UTF8 = 'UTF-8';

    /**
     * Microsecond offset between the Windows and Unix epochs.
     */
    private const FILETIME_EPOCH_OFFSET_MICROSECONDS = 11644473600000000;

    /**
     * Number of Windows FILETIME ticks in one microsecond.
     */
    private const FILETIME_TICKS_PER_MICROSECOND = 10;

    /**
     * Named timestamp capture in serialized submission identifiers.
     */
    private const DATE_TYPE = 'date';

    /**
     * Decode a null-terminated UTF-16LE property into UTF-8.
     */
    public static function decodeUtf16(string $raw): string
    {
        return rtrim(mb_convert_encoding($raw, self::UTF8, 'UTF-16LE'), "\0");
    }

    /**
     * Decode an ANSI property using its declared Windows codepage.
     */
    public static function decodeAnsi(string $raw, ?int $codepage): string
    {
        Properties::init();

        $encoding = Properties::$codepages[$codepage ?? 1252] ?? 'windows-1252';

        if (! mb_check_encoding($raw, $encoding)) {
            $encoding = self::detectLegacyEncoding($raw, $encoding) ?? $encoding;
        }

        $decoded = mb_convert_encoding($raw, self::UTF8, $encoding);

        return rtrim($decoded === false ? $raw : $decoded, "\0");
    }

    /**
     * Decode HTML bytes using their declared or detected encoding.
     */
    private static function decodeHtml(string $raw, ?int $codepage): string
    {
        $hasBom = str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF");

        if ($hasBom) {
            $decoded = mb_convert_encoding($raw, self::UTF8, 'UTF-16');

            return rtrim($decoded, "\0");
        }

        if (mb_check_encoding($raw, self::UTF8)) {
            return rtrim($raw, "\0");
        }

        $declaredEncoding = self::htmlDeclaredEncoding($raw);
        if ($declaredEncoding !== null && mb_check_encoding($raw, $declaredEncoding)) {
            $decoded = mb_convert_encoding($raw, self::UTF8, $declaredEncoding);

            return $decoded === false ? self::decodeAnsi($raw, $codepage) : rtrim($decoded, "\0");
        }

        return self::decodeAnsi($raw, $codepage);
    }

    /**
     * Detect a compatible legacy Windows encoding after the declared encoding fails.
     */
    private static function detectLegacyEncoding(string $raw, string $declared): ?string
    {
        $supported = array_fill_keys(array_map(strtolower(...), mb_list_encodings()), true);
        $candidates = array_values(array_unique(array_filter(
            ['CP936', 'CP950', 'CP949', 'SJIS-win', 'Windows-1252', 'Windows-1251'],
            static fn (string $encoding): bool => strcasecmp($encoding, $declared) !== 0
                && isset($supported[strtolower($encoding)]),
        )));
        $encoding = mb_detect_encoding($raw, $candidates, true);

        return is_string($encoding) ? $encoding : null;
    }

    /**
     * Extract a supported charset declaration from the leading HTML markup.
     */
    private static function htmlDeclaredEncoding(string $raw): ?string
    {
        $prefix = substr($raw, 0, 8192);
        $matched = preg_match('/charset\s*=\s*["\']?\s*([a-z0-9._-]+)/i', $prefix, $matches) === 1;

        if (! $matched) {
            return null;
        }

        $encoding = $matches[1];

        try {
            if (! mb_check_encoding('', $encoding)) {
                return null;
            }

            return $encoding;
        } catch (\ValueError) {
            return null;
        }
    }

    /**
     * Converts a FILETIME value (100-ns ticks since 1601-01-01) to DateTimeImmutable.
     */
    private static function toDateTime(BigInteger $value): ?DateTimeImmutable
    {
        $microseconds = $value->quotient(self::FILETIME_TICKS_PER_MICROSECOND);
        $unixMicroseconds = $microseconds->minus(self::FILETIME_EPOCH_OFFSET_MICROSECONDS);

        if ($unixMicroseconds->isLessThan(0)) {
            return null;
        }

        $seconds = $unixMicroseconds->quotient(1_000_000)->toInt();
        $micros = $unixMicroseconds->mod(1_000_000)->toInt();

        $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $micros));

        return $dt !== false ? $dt : null;
    }

    /**
     * Retain string values and represent other values as absent.
     */
    public static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Select the first nonempty string from alternative property values.
     */
    public static function firstStringOrNull(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Select the first usable email address from alternative properties.
     */
    public static function firstEmailOrNull(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $email = self::emailOrNull($value);
            if ($email !== null) {
                return $email;
            }
        }

        return null;
    }

    /**
     * Normalize an email value while rejecting empty addresses.
     */
    public static function emailOrNull(mixed $value): ?string
    {
        $isEmail = is_string($value) && str_contains($value, '@');

        return $isEmail ? $value : null;
    }

    /**
     * Retain immutable date values and represent other values as absent.
     */
    public static function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        return $value instanceof BigInteger ? self::toDateTime($value) : null;
    }

    /**
     * Recover a submission timestamp from its serialized identifier.
     */
    public static function submissionDateOrNull(mixed $value): ?DateTimeImmutable
    {
        $isString = is_string($value);

        if (! $isString) {
            return null;
        }

        $hasDate = preg_match('/-(?P<date>\d{12})Z?-[^-;\x00]+/', $value, $matches) === 1;

        if (! $hasDate) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!ymdHis',
            $matches[self::DATE_TYPE],
            new DateTimeZone('UTC'),
        );

        return $date !== false ? $date : null;
    }

    /**
     * Retain HTML strings and represent nonstring values as absent.
     */
    public static function htmlOrNull(mixed $value, ?int $codepage): ?string
    {
        return is_string($value) ? self::decodeHtml($value, $codepage) : null;
    }

    /**
     * Retain integer values and use zero for absent metadata.
     */
    public static function intOrZero(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    /**
     * Retain integer values and represent other values as absent.
     */
    public static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
