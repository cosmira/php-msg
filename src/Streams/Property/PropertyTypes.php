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

        self::$PtypInteger16 = new PropertyType(0x0002, 'PtypInteger16', 2, false);
        self::$PtypInteger32 = new PropertyType(0x0003, 'PtypInteger32', 4, false);
        self::$PtypFloating32 = new PropertyType(0x0004, 'PtypFloating32', 4, false);
        self::$PtypFloating64 = new PropertyType(0x0005, 'PtypFloating64', 8, false);
        self::$PtypBoolean = new PropertyType(0x000B, 'PtypBoolean', 1, false);
        self::$PtypCurrency = new PropertyType(0x0006, 'PtypCurrency', 8, false);
        self::$PtypFloatingTime = new PropertyType(0x0007, 'PtypFloatingTime', 8, false);
        self::$PtypTime = new PropertyType(0x0040, 'PtypTime', 8, false);
        self::$PtypInteger64 = new PropertyType(0x0014, 'PtypInteger64', 8, false);
        self::$PtypErrorCode = new PropertyType(0x000A, 'PtypErrorCode', 4, false);

        self::$PtypString = new PropertyType(0x001F, 'PtypString', null, false);
        self::$PtypString8 = new PropertyType(0x001E, 'PtypString8', null, false);
        self::$PtypBinary = new PropertyType(0x0102, 'PtypBinary', null, false);
        self::$PtypGuid = new PropertyType(0x0048, 'PtypGuid', 16, false);
        self::$PtypObject = new PropertyType(0x000D, 'PtypObject', null, false);

        self::$PtypMultipleInteger16 = new PropertyType(0x1002, 'PtypMultipleInteger16', 2, true);
        self::$PtypMultipleInteger32 = new PropertyType(0x1003, 'PtypMultipleInteger32', 4, true);
        self::$PtypMultipleFloating32 = new PropertyType(0x1004, 'PtypMultipleFloating32', 4, true);
        self::$PtypMultipleFloating64 = new PropertyType(0x1005, 'PtypMultipleFloating64', 8, true);
        self::$PtypMultipleCurrency = new PropertyType(0x1006, 'PtypMultipleCurrency', 8, true);
        self::$PtypMultipleFloatingTime = new PropertyType(0x1007, 'PtypMultipleFloatingTime', 8, true);
        self::$PtypMultipleTime = new PropertyType(0x1040, 'PtypMultipleTime', 8, true);
        self::$PtypMultipleGuid = new PropertyType(0x1048, 'PtypMultipleGuid', 16, true);
        self::$PtypMultipleInteger64 = new PropertyType(0x1014, 'PtypMultipleInteger64', 8, true);
        self::$PtypMultipleBinary = new PropertyType(0x1102, 'PtypMultipleBinary', null, true);
        self::$PtypMultipleString8 = new PropertyType(0x101E, 'PtypMultipleString8', null, true);
        self::$PtypMultipleString = new PropertyType(0x101F, 'PtypMultipleString', null, true);

        self::$MAP = [
            self::$PtypInteger16->id            => self::$PtypInteger16,
            self::$PtypInteger32->id            => self::$PtypInteger32,
            self::$PtypFloating32->id           => self::$PtypFloating32,
            self::$PtypFloating64->id           => self::$PtypFloating64,
            self::$PtypBoolean->id              => self::$PtypBoolean,
            self::$PtypCurrency->id             => self::$PtypCurrency,
            self::$PtypFloatingTime->id         => self::$PtypFloatingTime,
            self::$PtypTime->id                 => self::$PtypTime,
            self::$PtypInteger64->id            => self::$PtypInteger64,
            self::$PtypErrorCode->id            => self::$PtypErrorCode,
            self::$PtypString->id               => self::$PtypString,
            self::$PtypString8->id              => self::$PtypString8,
            self::$PtypBinary->id               => self::$PtypBinary,
            self::$PtypGuid->id                 => self::$PtypGuid,
            self::$PtypObject->id               => self::$PtypObject,
            self::$PtypMultipleInteger16->id    => self::$PtypMultipleInteger16,
            self::$PtypMultipleInteger32->id    => self::$PtypMultipleInteger32,
            self::$PtypMultipleFloating32->id   => self::$PtypMultipleFloating32,
            self::$PtypMultipleFloating64->id   => self::$PtypMultipleFloating64,
            self::$PtypMultipleCurrency->id     => self::$PtypMultipleCurrency,
            self::$PtypMultipleFloatingTime->id => self::$PtypMultipleFloatingTime,
            self::$PtypMultipleTime->id         => self::$PtypMultipleTime,
            self::$PtypMultipleGuid->id         => self::$PtypMultipleGuid,
            self::$PtypMultipleInteger64->id    => self::$PtypMultipleInteger64,
            self::$PtypMultipleBinary->id       => self::$PtypMultipleBinary,
            self::$PtypMultipleString8->id      => self::$PtypMultipleString8,
            self::$PtypMultipleString->id       => self::$PtypMultipleString,
        ];
    }

    public static function get(int $id): ?PropertyType
    {
        self::init();

        return self::$MAP[$id] ?? null;
    }
}
