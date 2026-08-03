<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

final class Properties
{
    private const PROPERTY_FLAG_READABLE = 0x00000002;

    private const PROPERTY_FLAG_WRITABLE = 0x00000004;

    private const PROPERTY_FLAG_RW = self::PROPERTY_FLAG_READABLE | self::PROPERTY_FLAG_WRITABLE;

    /**
     * The Internet codepage property shared by all storage kinds.
     */
    public static PropertyDefinition $codepageProperty;

    /**
     * The property definitions accepted on root message storages.
     *
     * @var PropertyDefinition[]
     */
    public static array $rootProperties = [];

    /**
     * The property definitions accepted on attachment storages.
     *
     * @var PropertyDefinition[]
     */
    public static array $attachmentProperties = [];

    /**
     * The property definitions accepted on recipient storages.
     *
     * @var PropertyDefinition[]
     */
    public static array $recipientProperties = [];

    /**
     * The character encodings keyed by Windows codepage.
     *
     * @var array<int, string>
     */
    public static array $codepages = [];

    /**
     * Initialize the shared MAPI property definitions.
     */
    public static function init(): void
    {
        if (self::$rootProperties !== []) {
            return;
        }

        PropertyTypes::init();

        self::$codepageProperty = new PropertyDefinition(
            '3FDE',
            'codepage',
            [PropertyTypes::$PtypInteger32],
            PropertySource::Property,
            self::PROPERTY_FLAG_RW,
        );

        self::$codepages = [
            874   => 'windows-874',
            932   => 'shift_jis',
            936   => 'gb2312',
            949   => 'big5',
            1200  => 'utf-16',
            1201  => 'utf-16be',
            1250  => 'windows-1250',
            1251  => 'windows-1251',
            1252  => 'windows-1252',
            1253  => 'windows-1253',
            1254  => 'windows-1254',
            1255  => 'windows-1255',
            1256  => 'windows-1256',
            1257  => 'windows-1257',
            1258  => 'windows-1258',
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

        self::$rootProperties = [
            new PropertyDefinition('0002', 'alternateRecipientAllowed', [PropertyTypes::$PtypBoolean], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0017', 'importance', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0026', 'priority', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0029', 'readReceiptRequested', [PropertyTypes::$PtypBoolean], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0039', 'clientSubmitTime', [PropertyTypes::$PtypTime], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0037', 'subject', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('003D', 'subjectPrefix', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0050', 'replyRecipientNames', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_READABLE),
            new PropertyDefinition('0070', 'conversationTopic', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('007d', 'headers', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0FF4', 'access', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0FF6', 'instanceKey', [PropertyTypes::$PtypBinary], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0FF7', 'accessLevel', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0FFF', 'entryId', [PropertyTypes::$PtypBinary], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0c1a', 'senderName', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0c19', 'senderEntryId', [PropertyTypes::$PtypBinary], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0c1e', 'senderAddressType', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0c1f', 'senderEmail', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0E01', 'deleteAfterSubmit', [PropertyTypes::$PtypBoolean], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0E02', 'bcc', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_READABLE),
            new PropertyDefinition('0E03', 'cc', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_READABLE),
            new PropertyDefinition('0E04', 'to', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_READABLE),
            new PropertyDefinition('0E06', 'date', [PropertyTypes::$PtypTime], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0E07', 'messageFlags', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0E08', 'messageSize', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0E1B', 'hasAttach', [PropertyTypes::$PtypBoolean], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0E1D', 'normalizedSubject', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0E1F', 'rtfInSync', [PropertyTypes::$PtypBoolean], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0FFE', 'objectType', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('1000', 'body', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('1009', 'bodyRtf', [PropertyTypes::$PtypBinary, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('1013', 'bodyHtml', [PropertyTypes::$PtypBinary], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('1080', 'iconIndex', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3007', 'creationTime', [PropertyTypes::$PtypTime], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3008', 'lastModificationTime', [PropertyTypes::$PtypTime], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('300B', 'searchKey', [PropertyTypes::$PtypBinary], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('340D', 'storeSupportMask', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_READABLE),
            new PropertyDefinition('340F', 'storeUnicodeMask', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_READABLE),
            new PropertyDefinition('3FF1', 'messageLocaleId', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('4022', 'creatorAddressType', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('4023', 'creatorEmail', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('4038', 'creatorDisplayName', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
        ];

        self::$attachmentProperties = [
            new PropertyDefinition('0E20', 'attachSize', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0E21', 'attachNum', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_READABLE),
            new PropertyDefinition('0FF6', 'instanceKey', [PropertyTypes::$PtypBinary], PropertySource::Stream, self::PROPERTY_FLAG_READABLE),
            new PropertyDefinition('0FF9', 'recordKey', [PropertyTypes::$PtypBinary], PropertySource::Stream, self::PROPERTY_FLAG_READABLE),
            new PropertyDefinition('0FFE', 'objectType', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3007', 'creationTime', [PropertyTypes::$PtypTime], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3008', 'lastModificationTime', [PropertyTypes::$PtypTime], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('340D', 'storeSupportMask', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_READABLE),
            new PropertyDefinition('3705', 'attachMethod', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('370B', 'renderingPosition', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_READABLE),
            new PropertyDefinition('3714', 'attachFlags', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3704', 'attachFileName', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3703', 'extension', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3707', 'fileName', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('370e', 'mimeType', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3712', 'contentId', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3A0C', 'language', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3001', 'displayName', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3701', 'content', [PropertyTypes::$PtypBinary], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3701', 'embeddedMsgObj', [PropertyTypes::$PtypObject], PropertySource::Stream, self::PROPERTY_FLAG_RW),
        ];

        self::$recipientProperties = [
            new PropertyDefinition('3000', 'rowId', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0C15', 'type', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0FFE', 'objectType', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0FF6', 'instanceKey', [PropertyTypes::$PtypBinary], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0FF9', 'recordKey', [PropertyTypes::$PtypBinary], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('0FFF', 'entryId', [PropertyTypes::$PtypBinary], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3001', 'name', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3002', 'addressType', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3003', 'email', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3003', 'emailAddress', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('300B', 'searchKey', [PropertyTypes::$PtypBinary], PropertySource::Stream, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('3900', 'displayType', [PropertyTypes::$PtypInteger32], PropertySource::Property, self::PROPERTY_FLAG_RW),
            new PropertyDefinition('39fe', 'email', [PropertyTypes::$PtypString, PropertyTypes::$PtypString8], PropertySource::Stream, self::PROPERTY_FLAG_RW),
        ];
    }
}
