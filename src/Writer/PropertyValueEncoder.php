<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\Mapi\PropertyType;

/** @internal */
final class PropertyValueEncoder
{
    /**
     * Encode a scalar MAPI value into its fixed binary representation.
     */
    public static function encode(PropertyType $type, mixed $value): string
    {
        return match ($type->id) {
            0x0002                 => self::integer16($value),
            0x0004                 => self::floating32($value),
            0x000B                 => self::boolean($value),
            0x0005, 0x0007         => self::floating64($value),
            0x0006, 0x0014, 0x0040 => self::integer64($value),
            default                => self::integer32($value),
        };
    }

    /**
     * Encode a signed 16-bit value in the fixed property slot.
     */
    private static function integer16(mixed $value): string
    {
        return pack('v', is_int($value) ? $value : 0).str_repeat("\0", 6);
    }

    /**
     * Encode a signed 32-bit value in the fixed property slot.
     */
    private static function integer32(mixed $value): string
    {
        return pack('V', is_int($value) ? $value : 0).pack('V', 0);
    }

    /**
     * Encode a single-precision value in little-endian form.
     */
    private static function floating32(mixed $value): string
    {
        $encoded = is_int($value)
            ? pack('V', $value)
            : pack('g', is_float($value) ? $value : 0);

        return $encoded.pack('V', 0);
    }

    /**
     * Encode a double-precision value in little-endian form.
     */
    private static function floating64(mixed $value): string
    {
        if ($value instanceof BigInteger) {
            return self::integer64($value);
        }

        $isNumeric = is_float($value) || is_int($value);

        return pack('e', $isNumeric ? $value : 0);
    }

    /**
     * Encode a boolean in the fixed MAPI property slot.
     */
    private static function boolean(mixed $value): string
    {
        return pack('V', (int) ((bool) $value)).pack('V', 0);
    }

    /**
     * Encode a 64-bit integer using its low and high 32-bit words.
     */
    private static function integer64(mixed $value): string
    {
        $isInteger = $value instanceof BigInteger || is_int($value) || is_string($value);
        $integer = $isInteger ? $value : 0;
        $bigInteger = $integer instanceof BigInteger ? $integer : BigInteger::of($integer);
        $low = $bigInteger->mod(1 << 32)->toInt();
        $high = $bigInteger->shiftedRight(32)->toInt();

        return pack('V', $low).pack('V', $high);
    }
}
