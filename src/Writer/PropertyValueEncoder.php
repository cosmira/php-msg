<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\Mapi\PropertyType;

/** @internal */
final class PropertyValueEncoder
{
    public static function encode(PropertyType $type, mixed $value): string
    {
        if ($type->id === 0x0002) {
            return self::integer16($value);
        }

        if ($type->id === 0x0004) {
            return self::floating32($value);
        }

        if ($type->id === 0x000B) {
            return self::boolean($value);
        }

        if (in_array($type->id, [0x0005, 0x0007], true)) {
            return self::floating64($value);
        }

        if (in_array($type->id, [0x0006, 0x0014, 0x0040], true)) {
            return self::integer64($value);
        }

        return self::integer32($value);
    }

    private static function integer16(mixed $value): string
    {
        return pack('v', is_int($value) ? $value : 0).str_repeat("\0", 6);
    }

    private static function integer32(mixed $value): string
    {
        return pack('V', is_int($value) ? $value : 0).pack('V', 0);
    }

    private static function floating32(mixed $value): string
    {
        $encoded = is_int($value)
            ? pack('V', $value)
            : pack('g', is_float($value) ? $value : 0);

        return $encoded.pack('V', 0);
    }

    private static function floating64(mixed $value): string
    {
        if ($value instanceof BigInteger) {
            return self::integer64($value);
        }

        $isNumeric = is_float($value) || is_int($value);

        return pack('e', $isNumeric ? $value : 0);
    }

    private static function boolean(mixed $value): string
    {
        return pack('V', (int) ((bool) $value)).pack('V', 0);
    }

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
