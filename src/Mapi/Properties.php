<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

final class Properties
{
    /**
     * Canonical instance key id used by this operation.
     */
    private const INSTANCE_KEY_ID = '0FF6';

    /**
     * Canonical instance key name used by this operation.
     */
    private const INSTANCE_KEY_NAME = 'instanceKey';

    /**
     * Canonical object type id used by this operation.
     */
    private const OBJECT_TYPE_ID = '0FFE';

    /**
     * Canonical object type name used by this operation.
     */
    private const OBJECT_TYPE_NAME = 'objectType';

    /**
     * MAPI flag marking a readable property.
     */
    private const PROPERTY_FLAG_READABLE = 0x00000002;

    /**
     * MAPI flag marking a writable property.
     */
    private const PROPERTY_FLAG_WRITABLE = 0x00000004;

    /**
     * Combined flags for readable and writable properties.
     */
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

        self::$codepageProperty = self::fixed('3FDE', 'codepage', [PropertyTypes::$PtypInteger32]);

        self::$codepages = self::codepageNames();

        self::$rootProperties = self::rootDefinitions();

        self::$attachmentProperties = self::attachmentDefinitions();

        self::$recipientProperties = self::recipientDefinitions();
    }

    /**
     * Return the Windows codepage names.
     *
     * @return array<int, string>
     */
    private static function codepageNames(): array
    {
        return [
            874   => 'windows-874',
            932   => 'SJIS-win',
            936   => 'CP936',
            949   => 'CP949',
            950   => 'CP950',
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
    }

    /**
     * Return the root storage property definitions.
     *
     * @return list<PropertyDefinition>
     */
    private static function rootDefinitions(): array
    {
        return [
            self::text('001A', 'messageClass'),
            self::fixed('0002', 'alternateRecipientAllowed', [PropertyTypes::$PtypBoolean]),
            self::fixed('0017', 'importance', [PropertyTypes::$PtypInteger32]),
            self::fixed('0026', 'priority', [PropertyTypes::$PtypInteger32]),
            self::fixed('0029', 'readReceiptRequested', [PropertyTypes::$PtypBoolean]),
            self::fixed('0039', 'clientSubmitTime', [PropertyTypes::$PtypTime]),
            self::text('0037', 'subject'),
            self::text('003D', 'subjectPrefix'),
            self::text('0042', 'sentRepresentingName', self::PROPERTY_FLAG_READABLE),
            self::stream('0047', 'messageSubmissionId', [PropertyTypes::$PtypBinary]),
            self::text('0050', 'replyRecipientNames', self::PROPERTY_FLAG_READABLE),
            self::text('0064', 'sentRepresentingAddressType', self::PROPERTY_FLAG_READABLE),
            self::text('0065', 'sentRepresentingEmail', self::PROPERTY_FLAG_READABLE),
            self::text('0070', 'conversationTopic'),
            self::text('007d', 'headers'),
            self::fixed('0FF4', 'access', [PropertyTypes::$PtypInteger32]),
            self::stream(
                self::INSTANCE_KEY_ID,
                self::INSTANCE_KEY_NAME,
                [PropertyTypes::$PtypBinary],
            ),
            self::fixed('0FF7', 'accessLevel', [PropertyTypes::$PtypInteger32]),
            self::stream('0FFF', 'entryId', [PropertyTypes::$PtypBinary]),
            self::text('0c1a', 'senderName'),
            self::stream('0c19', 'senderEntryId', [PropertyTypes::$PtypBinary]),
            self::text('0c1e', 'senderAddressType'),
            self::text('0c1f', 'senderEmail'),
            self::fixed('0E01', 'deleteAfterSubmit', [PropertyTypes::$PtypBoolean]),
            self::text('0E02', 'bcc', self::PROPERTY_FLAG_READABLE),
            self::text('0E03', 'cc', self::PROPERTY_FLAG_READABLE),
            self::text('0E04', 'to', self::PROPERTY_FLAG_READABLE),
            self::fixed('0E06', 'date', [PropertyTypes::$PtypTime]),
            self::fixed('0E07', 'messageFlags', [PropertyTypes::$PtypInteger32]),
            self::fixed('0E08', 'messageSize', [PropertyTypes::$PtypInteger32]),
            self::fixed('0E1B', 'hasAttach', [PropertyTypes::$PtypBoolean]),
            self::text('0E1D', 'normalizedSubject'),
            self::fixed('0E1F', 'rtfInSync', [PropertyTypes::$PtypBoolean]),
            self::fixed(
                self::OBJECT_TYPE_ID,
                self::OBJECT_TYPE_NAME,
                [PropertyTypes::$PtypInteger32],
            ),
            self::text('1000', 'body'),
            self::stream(
                '1009',
                'bodyRtf',
                [PropertyTypes::$PtypBinary, PropertyTypes::$PtypString8],
            ),
            self::stream(
                '1013',
                'bodyHtml',
                [PropertyTypes::$PtypBinary, PropertyTypes::$PtypString8],
            ),
            self::text('1035', 'internetMessageId'),
            self::text('1039', 'internetReferences'),
            self::text('1042', 'inReplyToId'),
            self::fixed('1080', 'iconIndex', [PropertyTypes::$PtypInteger32]),
            self::fixed('3007', 'creationTime', [PropertyTypes::$PtypTime]),
            self::fixed('3008', 'lastModificationTime', [PropertyTypes::$PtypTime]),
            self::stream('300B', 'searchKey', [PropertyTypes::$PtypBinary]),
            self::fixed(
                '340D',
                'storeSupportMask',
                [PropertyTypes::$PtypInteger32],
                self::PROPERTY_FLAG_READABLE,
            ),
            self::fixed(
                '340F',
                'storeUnicodeMask',
                [PropertyTypes::$PtypInteger32],
                self::PROPERTY_FLAG_READABLE,
            ),
            self::fixed('3FF1', 'messageLocaleId', [PropertyTypes::$PtypInteger32]),
            self::text('4022', 'creatorAddressType'),
            self::text('4023', 'creatorEmail'),
            self::text('4038', 'creatorDisplayName'),
            self::text('5D01', 'senderSmtpEmail', self::PROPERTY_FLAG_READABLE),
            self::text('5D0A', 'senderAlternateSmtpEmail', self::PROPERTY_FLAG_READABLE),
            self::fixed('5909', 'messageEditorFormat', [PropertyTypes::$PtypInteger32]),
        ];
    }

    /**
     * Return the attachment storage property definitions.
     *
     * @return list<PropertyDefinition>
     */
    private static function attachmentDefinitions(): array
    {
        return [
            self::fixed('0E20', 'attachSize', [PropertyTypes::$PtypInteger32]),
            self::fixed(
                '0E21',
                'attachNum',
                [PropertyTypes::$PtypInteger32],
                self::PROPERTY_FLAG_READABLE,
            ),
            self::stream(
                self::INSTANCE_KEY_ID,
                self::INSTANCE_KEY_NAME,
                [PropertyTypes::$PtypBinary],
                self::PROPERTY_FLAG_READABLE,
            ),
            self::stream(
                '0FF9',
                'recordKey',
                [PropertyTypes::$PtypBinary],
                self::PROPERTY_FLAG_READABLE,
            ),
            self::fixed(
                self::OBJECT_TYPE_ID,
                self::OBJECT_TYPE_NAME,
                [PropertyTypes::$PtypInteger32],
            ),
            self::fixed('3007', 'creationTime', [PropertyTypes::$PtypTime]),
            self::fixed('3008', 'lastModificationTime', [PropertyTypes::$PtypTime]),
            self::fixed(
                '340D',
                'storeSupportMask',
                [PropertyTypes::$PtypInteger32],
                self::PROPERTY_FLAG_READABLE,
            ),
            self::fixed('3705', 'attachMethod', [PropertyTypes::$PtypInteger32]),
            self::fixed(
                '370B',
                'renderingPosition',
                [PropertyTypes::$PtypInteger32],
                self::PROPERTY_FLAG_READABLE,
            ),
            self::fixed('3714', 'attachFlags', [PropertyTypes::$PtypInteger32]),
            self::text('3704', 'attachFileName'),
            self::text('3703', 'extension'),
            self::text('3707', 'fileName'),
            self::text('370e', 'mimeType'),
            self::text('3712', 'contentId'),
            self::text('3A0C', 'language'),
            self::text('3001', 'displayName'),
            self::stream('3701', 'content', [PropertyTypes::$PtypBinary]),
            self::stream('3701', 'embeddedMsgObj', [PropertyTypes::$PtypObject]),
        ];
    }

    /**
     * Return the recipient storage property definitions.
     *
     * @return list<PropertyDefinition>
     */
    private static function recipientDefinitions(): array
    {
        return [
            self::fixed('3000', 'rowId', [PropertyTypes::$PtypInteger32]),
            self::fixed('0C15', 'type', [PropertyTypes::$PtypInteger32]),
            self::fixed(
                self::OBJECT_TYPE_ID,
                self::OBJECT_TYPE_NAME,
                [PropertyTypes::$PtypInteger32],
            ),
            self::stream(
                self::INSTANCE_KEY_ID,
                self::INSTANCE_KEY_NAME,
                [PropertyTypes::$PtypBinary],
            ),
            self::stream('0FF9', 'recordKey', [PropertyTypes::$PtypBinary]),
            self::stream('0FFF', 'entryId', [PropertyTypes::$PtypBinary]),
            self::text('3001', 'name'),
            self::text('3002', 'addressType'),
            self::text('3003', 'email'),
            self::text('3003', 'emailAddress'),
            self::stream('300B', 'searchKey', [PropertyTypes::$PtypBinary]),
            self::fixed('3900', 'displayType', [PropertyTypes::$PtypInteger32]),
            self::text('39fe', 'email'),
        ];
    }

    /**
     * Define a fixed property with the standard readable and writable flags.
     *
     * @param list<PropertyType> $types
     */
    private static function fixed(
        string $id,
        string $name,
        array $types,
        ?int $flags = null,
    ): PropertyDefinition {
        return new PropertyDefinition(
            $id,
            $name,
            $types,
            PropertySource::Property,
            $flags ?? self::PROPERTY_FLAG_RW,
        );
    }

    /**
     * Define a dedicated stream with the standard readable and writable flags.
     *
     * @param list<PropertyType> $types
     */
    private static function stream(
        string $id,
        string $name,
        array $types,
        ?int $flags = null,
    ): PropertyDefinition {
        return new PropertyDefinition(
            $id,
            $name,
            $types,
            PropertySource::Stream,
            $flags ?? self::PROPERTY_FLAG_RW,
        );
    }

    /**
     * Define a text stream accepting both Unicode and ANSI representations.
     */
    private static function text(string $id, string $name, ?int $flags = null): PropertyDefinition
    {
        return self::stream(
            $id,
            $name,
            [PropertyTypes::$PtypString, PropertyTypes::$PtypString8],
            $flags,
        );
    }
}
