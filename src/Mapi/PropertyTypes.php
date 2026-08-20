<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

final class PropertyTypes
{
    /**
     * The signed 16-bit integer MAPI type.
     */
    public static PropertyType $PtypInteger16;

    /**
     * The signed 32-bit integer MAPI type.
     */
    public static PropertyType $PtypInteger32;

    /**
     * The 32-bit floating-point MAPI type.
     */
    public static PropertyType $PtypFloating32;

    /**
     * The 64-bit floating-point MAPI type.
     */
    public static PropertyType $PtypFloating64;

    /**
     * The Boolean MAPI type.
     */
    public static PropertyType $PtypBoolean;

    /**
     * The scaled 64-bit currency MAPI type.
     */
    public static PropertyType $PtypCurrency;

    /**
     * The floating-point time MAPI type.
     */
    public static PropertyType $PtypFloatingTime;

    /**
     * The FILETIME-backed MAPI type.
     */
    public static PropertyType $PtypTime;

    /**
     * The signed 64-bit integer MAPI type.
     */
    public static PropertyType $PtypInteger64;

    /**
     * The 32-bit error-code MAPI type.
     */
    public static PropertyType $PtypErrorCode;

    /**
     * The UTF-16 string MAPI type.
     */
    public static PropertyType $PtypString;

    /**
     * The codepage string MAPI type.
     */
    public static PropertyType $PtypString8;

    /**
     * The arbitrary binary MAPI type.
     */
    public static PropertyType $PtypBinary;

    /**
     * The GUID MAPI type.
     */
    public static PropertyType $PtypGuid;

    /**
     * The embedded object MAPI type.
     */
    public static PropertyType $PtypObject;

    /**
     * The multi-valued 16-bit integer MAPI type.
     */
    public static PropertyType $PtypMultipleInteger16;

    /**
     * The multi-valued 32-bit integer MAPI type.
     */
    public static PropertyType $PtypMultipleInteger32;

    /**
     * The multi-valued 32-bit floating-point MAPI type.
     */
    public static PropertyType $PtypMultipleFloating32;

    /**
     * The multi-valued 64-bit floating-point MAPI type.
     */
    public static PropertyType $PtypMultipleFloating64;

    /**
     * The multi-valued currency MAPI type.
     */
    public static PropertyType $PtypMultipleCurrency;

    /**
     * The multi-valued floating-time MAPI type.
     */
    public static PropertyType $PtypMultipleFloatingTime;

    /**
     * The multi-valued FILETIME MAPI type.
     */
    public static PropertyType $PtypMultipleTime;

    /**
     * The multi-valued GUID MAPI type.
     */
    public static PropertyType $PtypMultipleGuid;

    /**
     * The multi-valued 64-bit integer MAPI type.
     */
    public static PropertyType $PtypMultipleInteger64;

    /**
     * The multi-valued binary MAPI type.
     */
    public static PropertyType $PtypMultipleBinary;

    /**
     * The multi-valued codepage string MAPI type.
     */
    public static PropertyType $PtypMultipleString8;

    /**
     * The multi-valued UTF-16 string MAPI type.
     */
    public static PropertyType $PtypMultipleString;

    /**
     * The property-type registry keyed by numeric identifier.
     *
     * @var array<int, PropertyType>
     */
    public static array $MAP = [];

    /**
     * Initialize the shared MAPI property-type definitions.
     */
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
            [0x0048, 'PtypGuid', null, false],
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
            self::registerNamedType($name, $type);
        }
    }

    private static function registerNamedType(string $name, PropertyType $type): void
    {
        match ($name) {
            'PtypInteger16'            => self::$PtypInteger16 = $type,
            'PtypInteger32'            => self::$PtypInteger32 = $type,
            'PtypFloating32'           => self::$PtypFloating32 = $type,
            'PtypFloating64'           => self::$PtypFloating64 = $type,
            'PtypBoolean'              => self::$PtypBoolean = $type,
            'PtypCurrency'             => self::$PtypCurrency = $type,
            'PtypFloatingTime'         => self::$PtypFloatingTime = $type,
            'PtypTime'                 => self::$PtypTime = $type,
            'PtypInteger64'            => self::$PtypInteger64 = $type,
            'PtypErrorCode'            => self::$PtypErrorCode = $type,
            'PtypString'               => self::$PtypString = $type,
            'PtypString8'              => self::$PtypString8 = $type,
            'PtypBinary'               => self::$PtypBinary = $type,
            'PtypGuid'                 => self::$PtypGuid = $type,
            'PtypObject'               => self::$PtypObject = $type,
            'PtypMultipleInteger16'    => self::$PtypMultipleInteger16 = $type,
            'PtypMultipleInteger32'    => self::$PtypMultipleInteger32 = $type,
            'PtypMultipleFloating32'   => self::$PtypMultipleFloating32 = $type,
            'PtypMultipleFloating64'   => self::$PtypMultipleFloating64 = $type,
            'PtypMultipleCurrency'     => self::$PtypMultipleCurrency = $type,
            'PtypMultipleFloatingTime' => self::$PtypMultipleFloatingTime = $type,
            'PtypMultipleTime'         => self::$PtypMultipleTime = $type,
            'PtypMultipleGuid'         => self::$PtypMultipleGuid = $type,
            'PtypMultipleInteger64'    => self::$PtypMultipleInteger64 = $type,
            'PtypMultipleBinary'       => self::$PtypMultipleBinary = $type,
            'PtypMultipleString8'      => self::$PtypMultipleString8 = $type,
            'PtypMultipleString'       => self::$PtypMultipleString = $type,
            default                    => throw new \LogicException(sprintf('Unknown property type name "%s".', $name)),
        };
    }

    /**
     * Get a MAPI property type by its numeric identifier.
     */
    public static function get(int $id): ?PropertyType
    {
        self::init();

        return self::$MAP[$id] ?? null;
    }
}
