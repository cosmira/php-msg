<?php

declare(strict_types=1);

namespace MsgViewer\Streams\Property;

final class Properties
{
    public static PropertyDefinition $CODEPAGE_PROPERTY;

    /** @var PropertyDefinition[] */
    public static array $ROOT_PROPERTIES = [];

    /** @var PropertyDefinition[] */
    public static array $ATTACH_PROPERTIES = [];

    /** @var PropertyDefinition[] */
    public static array $RECIP_PROPERTIES = [];

    /** @var array<int, string> */
    public static array $CODEPAGES = [];

    public static function init(): void
    {
        if (self::$ROOT_PROPERTIES !== []) {
            return;
        }

        PropertyTypes::init();

        self::$CODEPAGE_PROPERTY = new PropertyDefinition(
            '3FDE',
            'codepage',
            [PropertyTypes::$PtypInteger32],
            PropertySource::Property
        );

        self::$CODEPAGES = [
            874 => 'windows-874',
            932 => 'shift_jis',
            936 => 'gb2312',
            949 => 'big5',
            1200 => 'utf-16',
            1201 => 'utf-16be',
            1250 => 'windows-1250',
            1251 => 'windows-1251',
            1252 => 'windows-1252',
            1253 => 'windows-1253',
            1254 => 'windows-1254',
            1255 => 'windows-1255',
            1256 => 'windows-1256',
            1257 => 'windows-1257',
            1258 => 'windows-1258',
            20127 => 'us-ascii',
            20866 => 'koi8-r',
            21866 => 'koi8-u',
            28591 => 'iso-8859-1',
            28592 => 'iso-8859-2',
            28593 => 'iso-8859-3',
            28594 => 'iso-8859-4',
            28595 => 'iso-8859-5',
            28596 => 'iso-8859-6',
            28597 => 'iso-8859-7',
            38598 => 'iso-8859-8',
            28599 => 'iso-8859-9',
            28603 => 'iso-8859-13',
            28604 => 'iso-8859-14',
            28605 => 'iso-8859-15',
            28606 => 'iso-8859-16',
            50220 => 'iso-2022-jp',
            50221 => 'csISO2022JP',
            51932 => 'euc-jp',
            51949 => 'euc-kr',
            52936 => 'gb_2312',
            65001 => 'utf-8',
        ];

        self::$ROOT_PROPERTIES = [
            new PropertyDefinition('0E06', 'date', [PropertyTypes::$PtypTime], PropertySource::Property),
            new PropertyDefinition('0037', 'subject', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('0c1a', 'senderName', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('0c1f', 'senderEmail', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('1000', 'body', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('1013', 'bodyHTML', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('1009', 'bodyRTF', [PropertyTypes::$PtypBinary, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('007d', 'headers', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('0E04', 'toRecipients', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('0E03', 'ccRecipients', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
        ];

        self::$ATTACH_PROPERTIES = [
            new PropertyDefinition('3703', 'extension', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('3707', 'fileName', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('370e', 'mimeType', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('3A0C', 'language', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('3001', 'displayName', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('3701', 'content', [PropertyTypes::$PtypBinary], PropertySource::Stream),
            new PropertyDefinition('3701', 'embeddedMsgObj', [PropertyTypes::$PtypObject], PropertySource::Stream),
        ];

        self::$RECIP_PROPERTIES = [
            new PropertyDefinition('3001', 'name', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
            new PropertyDefinition('39fe', 'email', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream),
        ];
    }
}

