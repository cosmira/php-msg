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
        $setters = [
            'PtypInteger16'            => static fn (PropertyType $value) => self::$PtypInteger16 = $value,
            'PtypInteger32'            => static fn (PropertyType $value) => self::$PtypInteger32 = $value,
            'PtypFloating32'           => static fn (PropertyType $value) => self::$PtypFloating32 = $value,
            'PtypFloating64'           => static fn (PropertyType $value) => self::$PtypFloating64 = $value,
            'PtypBoolean'              => static fn (PropertyType $value) => self::$PtypBoolean = $value,
            'PtypCurrency'             => static fn (PropertyType $value) => self::$PtypCurrency = $value,
            'PtypFloatingTime'         => static fn (PropertyType $value) => self::$PtypFloatingTime = $value,
            'PtypTime'                 => static fn (PropertyType $value) => self::$PtypTime = $value,
            'PtypInteger64'            => static fn (PropertyType $value) => self::$PtypInteger64 = $value,
            'PtypErrorCode'            => static fn (PropertyType $value) => self::$PtypErrorCode = $value,
            'PtypString'               => static fn (PropertyType $value) => self::$PtypString = $value,
            'PtypString8'              => static fn (PropertyType $value) => self::$PtypString8 = $value,
            'PtypBinary'               => static fn (PropertyType $value) => self::$PtypBinary = $value,
            'PtypGuid'                 => static fn (PropertyType $value) => self::$PtypGuid = $value,
            'PtypObject'               => static fn (PropertyType $value) => self::$PtypObject = $value,
            'PtypMultipleInteger16'    => static fn (PropertyType $value) => self::$PtypMultipleInteger16 = $value,
            'PtypMultipleInteger32'    => static fn (PropertyType $value) => self::$PtypMultipleInteger32 = $value,
            'PtypMultipleFloating32'   => static fn (PropertyType $value) => self::$PtypMultipleFloating32 = $value,
            'PtypMultipleFloating64'   => static fn (PropertyType $value) => self::$PtypMultipleFloating64 = $value,
            'PtypMultipleCurrency'     => static fn (PropertyType $value) => self::$PtypMultipleCurrency = $value,
            'PtypMultipleFloatingTime' => static fn (PropertyType $value) => self::$PtypMultipleFloatingTime = $value,
            'PtypMultipleTime'         => static fn (PropertyType $value) => self::$PtypMultipleTime = $value,
            'PtypMultipleGuid'         => static fn (PropertyType $value) => self::$PtypMultipleGuid = $value,
            'PtypMultipleInteger64'    => static fn (PropertyType $value) => self::$PtypMultipleInteger64 = $value,
            'PtypMultipleBinary'       => static fn (PropertyType $value) => self::$PtypMultipleBinary = $value,
            'PtypMultipleString8'      => static fn (PropertyType $value) => self::$PtypMultipleString8 = $value,
            'PtypMultipleString'       => static fn (PropertyType $value) => self::$PtypMultipleString = $value,
        ];
        $setter = $setters[$name] ?? null;

        if ($setter === null) {
            throw new \LogicException(sprintf('Unknown property type name "%s".', $name));
        }

        $setter($type);
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
