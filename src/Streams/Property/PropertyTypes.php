<?php

declare(strict_types=1);

namespace MsgViewer\Streams\Property;

final class PropertyTypes
{
    public static PropertyType $PtypInteger16;
    public static PropertyType $PtypInteger32;
    public static PropertyType $PtypFloating32;
    public static PropertyType $PtypFloating64;
    public static PropertyType $PtypBoolean;
    public static PropertyType $PtypCurrency;
    public static PropertyType $PtypFloatingTime;
    public static PropertyType $PtypTime;
    public static PropertyType $PtypInteger64;
    public static PropertyType $PtypErrorCode;

    public static PropertyType $PtypString;
    public static PropertyType $PtypString8;
    public static PropertyType $PtypBinary;
    public static PropertyType $PtypGuid;
    public static PropertyType $PtypObject;

    public static PropertyType $PtypMultipleInteger16;
    public static PropertyType $PtypMultipleInteger32;
    public static PropertyType $PtypMultipleFloating32;
    public static PropertyType $PtypMultipleFloating64;
    public static PropertyType $PtypMultipleCurrency;
    public static PropertyType $PtypMultipleFloatingTime;
    public static PropertyType $PtypMultipleTime;
    public static PropertyType $PtypMultipleGuid;
    public static PropertyType $PtypMultipleInteger64;
    public static PropertyType $PtypMultipleBinary;
    public static PropertyType $PtypMultipleString8;
    public static PropertyType $PtypMultipleString;

    /** @var array<int, PropertyType> */
    public static array $MAP = [];

    public static function init(): void
    {
        if (self::$MAP !== []) {
            return;
        }

        $definitions = [
            // [ID, PropertyType Name, size, isMultiple]
            [0x0002, 'PtypInteger16', 2, false],
            [0x0003, 'PtypInteger32', 4, false],
            [0x0004, 'PtypFloating32', 4, false],
            [0x0005, 'PtypFloating64', 8, false],
            [0x000B, 'PtypBoolean', 1, false],
            [0x0006, 'PtypCurrency', 8, false],
            [0x0007, 'PtypFloatingTime', 8, false],
            [0x0040, 'PtypTime', 8, false],
            [0x0014, 'PtypInteger64', 8, false],
            [0x000A, 'PtypErrorCode', 4, false],
            [0x001F, 'PtypString', null, false],
            [0x001E, 'PtypString8', null, false],
            [0x0102, 'PtypBinary', null, false],
            [0x0048, 'PtypGuid', 16, false],
            [0x000D, 'PtypObject', null, false],
            [0x1002, 'PtypMultipleInteger16', 2, true],
            [0x1003, 'PtypMultipleInteger32', 4, true],
            [0x1004, 'PtypMultipleFloating32', 4, true],
            [0x1005, 'PtypMultipleFloating64', 8, true],
            [0x1006, 'PtypMultipleCurrency', 8, true],
            [0x1007, 'PtypMultipleFloatingTime', 8, true],
            [0x1040, 'PtypMultipleTime', 8, true],
            [0x1048, 'PtypMultipleGuid', 16, true],
            [0x1014, 'PtypMultipleInteger64', 8, true],
            [0x1102, 'PtypMultipleBinary', null, true],
            [0x101E, 'PtypMultipleString8', null, true],
            [0x101F, 'PtypMultipleString', null, true],
        ];

        foreach ($definitions as [$id, $name, $size, $multiple]) {
            $type = new PropertyType($id, $name, $size, $multiple);
            self::$MAP[$id] = $type;

            // Статическое свойство: PropertyTypes::$PtypInteger32 = $type;
            self::${$name} = $type;
        }
    }

    public static function get(int $id): ?PropertyType
    {
        self::init();

        return self::$MAP[$id] ?? null;
    }
}
