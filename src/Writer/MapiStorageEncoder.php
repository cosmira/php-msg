<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\Mapi\Properties;
use Cosmira\OutlookMessage\Mapi\PropertyDefinition;
use Cosmira\OutlookMessage\Mapi\PropertySource;
use Cosmira\OutlookMessage\Mapi\PropertyType;
use Cosmira\OutlookMessage\Mapi\PropertyTypes;
use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Recipient;
use Cosmira\OutlookMessage\Rtf\RtfCompressor;
use DateTimeImmutable;
use LogicException;

final class MapiStorageEncoder
{
    private const CODEPAGE = 65001;

    private const MESSAGE_CLASS = 'IPM.Note';

    private const MESSAGE_FLAGS_UNMODIFIED = 0x0002;

    private const MESSAGE_FLAGS_UNSENT = 0x0008;

    private const MESSAGE_FLAGS_HAS_ATTACH = 0x0010;

    private const OBJECT_TYPE_MESSAGE = 5;

    private const ICON_INDEX_UNSENT_MAIL = 0x00000103;

    private const IMPORTANCE_NORMAL = 1;

    private const PRIORITY_NONURGENT = 0;

    private const ACCESS_READ_WRITE_DELETE = 7;

    private const ACCESS_LEVEL_MODIFY = 1;

    private const STORE_SUPPORT_MASK = 0x00050038;

    private const MESSAGE_LOCALE_ID = 1033;

    private const SENDER_ADDRESS_TYPE = 'SMTP';

    private const RECIPIENT_OBJECT_TYPE_MAILUSER = 6;

    private const RECIPIENT_DISPLAY_TYPE_MAILUSER = 0;

    private const ONE_OFF_ENTRY_ID_PROVIDER_UID = "\x81\x2B\x1F\xA4\xBE\xA3\x10\x19\x9D\x6E\x00\xDD\x01\x0F\x54\x02";

    private const ATTACH_METHOD_EMBEDDED_MESSAGE = 5;

    private const ATTACH_METHOD_BY_VALUE = 1;

    private const ATTACH_FLAG_RENDERED_IN_BODY = 0x04;

    private const OBJECT_TYPE_ATTACH = 7;

    public static function forMessage(MessageBuilder $builder, int $subStorageSize = 0): StorageStreams
    {
        self::bootMapi();

        $metadataTimestamp = $builder->date ?? new DateTimeImmutable('now');
        [$subjectPrefix, $normalizedSubject] = self::splitSubject($builder->subject);
        $hasAttachments = $builder->attachments() !== [];
        $messageFlags = self::MESSAGE_FLAGS_UNMODIFIED | self::MESSAGE_FLAGS_UNSENT;
        $searchKey = random_bytes(16);

        if ($hasAttachments) {
            $messageFlags |= self::MESSAGE_FLAGS_HAS_ATTACH;
        }

        $values = [
            'access'                    => self::ACCESS_READ_WRITE_DELETE,
            'accessLevel'               => self::ACCESS_LEVEL_MODIFY,
            'alternateRecipientAllowed' => 1,
            'codepage'                  => self::CODEPAGE,
            'creationTime'              => self::unixToFiletime($metadataTimestamp),
            'deleteAfterSubmit'         => 0,
            'hasAttach'                 => $hasAttachments ? 1 : 0,
            'iconIndex'                 => self::ICON_INDEX_UNSENT_MAIL,
            'importance'                => self::IMPORTANCE_NORMAL,
            'lastModificationTime'      => self::unixToFiletime($metadataTimestamp),
            'messageFlags'              => $messageFlags,
            'messageLocaleId'           => self::MESSAGE_LOCALE_ID,
            'objectType'                => self::OBJECT_TYPE_MESSAGE,
            'priority'                  => self::PRIORITY_NONURGENT,
            'readReceiptRequested'      => 0,
            'rtfInSync'                 => $builder->bodyRtf !== null ? 1 : 0,
            'storeSupportMask'          => self::STORE_SUPPORT_MASK,
            'storeUnicodeMask'          => self::STORE_SUPPORT_MASK,
        ];

        if ($builder->date instanceof DateTimeImmutable) {
            $values['clientSubmitTime'] = self::unixToFiletime($builder->date);
            $values['date'] = self::unixToFiletime($builder->date);
        }

        $streams = [];

        $streams += self::encodeBinaryProperty('300B', $searchKey);

        if ($builder->subject !== null) {
            $streams += self::encodeStringProperty('0037', $builder->subject);
            $streams += $subjectPrefix === ''
                ? self::encodeEmptyStringProperty('003D')
                : self::encodeStringProperty('003D', $subjectPrefix);
            $streams += self::encodeStringProperty('0070', $normalizedSubject);
            $streams += self::encodeStringProperty('0E1D', $normalizedSubject);
        }

        $streams += self::encodeEmptyStringProperty('0050');

        if ($builder->senderName !== null && $builder->senderEmail === null) {
            $streams += self::encodeStringPropertyWithoutTerminator('0c1a', $builder->senderName);
        }

        if ($builder->senderEmail !== null) {
            $senderDisplayName = $builder->senderName ?? $builder->senderEmail;
            $senderEntryId = self::oneOffEntryId($builder->senderEmail, $senderDisplayName);
            $streams += self::encodeBinaryProperty('0c19', $senderEntryId);
            $streams += self::encodeStringPropertyWithoutTerminator('0c1a', $senderDisplayName);
            $streams += self::encodeStringPropertyWithoutTerminator('0c1e', self::SENDER_ADDRESS_TYPE);
            $streams += self::encodeStringPropertyWithoutTerminator('0c1f', $builder->senderEmail);
            $streams += self::encodeStringPropertyWithoutTerminator('4022', self::SENDER_ADDRESS_TYPE);
            $streams += self::encodeStringPropertyWithoutTerminator('4023', $builder->senderEmail);
            $streams += self::encodeStringPropertyWithoutTerminator('4038', $senderDisplayName);
        }

        if ($builder->body !== null) {
            $streams += $builder->body === ''
                ? self::encodeEmptyStringProperty('1000')
                : self::encodeStringProperty('1000', $builder->body);
        }

        if ($builder->bodyHtml !== null) {
            $streams += self::encodeBinaryProperty('1013', $builder->bodyHtml);
        }

        if ($builder->bodyRtf !== null) {
            $streams += self::encodeBinaryProperty(
                '1009',
                $builder->bodyRtfCompressed ?? RtfCompressor::wrapUncompressed($builder->bodyRtf),
            );
        }

        if ($builder->headers !== null) {
            $streams += self::encodeStringProperty('007d', $builder->headers);
        }

        self::addDisplayRecipients($streams, '0E04', $builder->recipients(), Recipient::TYPE_TO);
        self::addDisplayRecipients($streams, '0E03', $builder->recipients(), Recipient::TYPE_CC);
        self::addDisplayRecipients($streams, '0E02', $builder->recipients(), Recipient::TYPE_BCC);

        $streams += self::encodeStringProperty('001A', self::MESSAGE_CLASS);

        $definitions = array_merge(
            [
                new PropertyDefinition(
                    '001A',
                    'messageClass',
                    [PropertyTypes::$PtypString],
                    PropertySource::Stream,
                    0x00000006,
                ),
            ],
            Properties::$rootProperties,
            [Properties::$codepageProperty]
        );

        $storage = self::buildStorageStreams(
            $definitions,
            $values,
            $streams,
            true,
            count($builder->recipients()),
            count($builder->attachments()),
            $subStorageSize,
        );

        return self::appendRawProperties($storage, $builder->rawProperties());
    }

    public static function forRecipient(RecipientPayload $recipient, int $rowId = 0): StorageStreams
    {
        self::bootMapi();

        $streams = [];
        $values = [
            'displayType' => self::RECIPIENT_DISPLAY_TYPE_MAILUSER,
            'objectType'  => self::RECIPIENT_OBJECT_TYPE_MAILUSER,
            'rowId'       => $rowId,
            'type'        => $recipient->type,
        ];
        $displayName = $recipient->display() ?? '';
        $entryId = $recipient->email !== null
            ? self::oneOffEntryId($recipient->email, $displayName)
            : self::randomUuidUtf16();
        $streams += self::encodeBinaryProperty('0FF6', random_bytes(4));
        $streams += self::encodeBinaryProperty('0FF9', $entryId);
        $streams += self::encodeBinaryProperty('0FFF', $entryId);

        if ($recipient->name !== null) {
            $streams += self::encodeStringPropertyWithoutTerminator('3001', $recipient->name);
        }

        if ($recipient->email !== null) {
            $streams += self::encodeStringPropertyWithoutTerminator('3002', self::SENDER_ADDRESS_TYPE);
            $streams += self::encodeStringPropertyWithoutTerminator('3003', $recipient->email);
            $streams += self::encodeBinaryProperty('300B', self::recipientSearchKey($recipient->email));
        }

        $storage = self::buildStorageStreams(
            Properties::$recipientProperties,
            $values,
            $streams,
            false,
        );

        return self::appendRawProperties($storage, $recipient->rawProperties);
    }

    public static function forAttachment(AttachmentPayload $attachment, int $attachNum = 0): StorageStreams
    {
        self::bootMapi();

        $streams = [];
        $timestamp = new DateTimeImmutable('now');
        $recordKey = random_bytes(16);
        $values = [
            'attachMethod'         => self::ATTACH_METHOD_BY_VALUE,
            'attachNum'            => $attachNum,
            'attachSize'           => strlen($attachment->content),
            'creationTime'         => self::unixToFiletime($timestamp),
            'instanceKey'          => null,
            'lastModificationTime' => self::unixToFiletime($timestamp),
            'objectType'           => self::OBJECT_TYPE_ATTACH,
            'renderingPosition'    => 0xFFFFFFFF,
            'storeSupportMask'     => self::STORE_SUPPORT_MASK,
        ];
        $streams += self::encodeBinaryProperty('0FF6', random_bytes(4));
        $streams += self::encodeBinaryProperty('0FF9', $recordKey);

        if ($attachment->extension !== null) {
            $streams += self::encodeStringProperty('3703', $attachment->extension);
        }

        if ($attachment->fileName !== null) {
            $streams += self::encodeStringProperty('3704', $attachment->fileName);
            $streams += self::encodeStringProperty('3707', $attachment->fileName);
        }

        if ($attachment->mimeType !== null) {
            $streams += self::encodeStringProperty('370e', $attachment->mimeType);
        }

        if ($attachment->language !== null) {
            $streams += self::encodeStringProperty('3A0C', $attachment->language);
        }

        if ($attachment->displayName !== null) {
            $streams += self::encodeStringProperty('3001', $attachment->displayName);
        }

        if ($attachment->contentId !== null) {
            $streams += self::encodeStringProperty('3712', $attachment->contentId);
        }

        $streams += self::encodeBinaryProperty('3701', $attachment->content);

        if ($attachment->isInline) {
            $values['attachFlags'] = self::ATTACH_FLAG_RENDERED_IN_BODY;
        }

        $storage = self::buildStorageStreams(
            Properties::$attachmentProperties,
            $values,
            $streams,
            false,
        );

        return self::appendRawProperties($storage, $attachment->rawProperties);
    }

    public static function forEmbeddedAttachment(AttachmentPayload $attachment, int $attachNum = 0): StorageStreams
    {
        self::bootMapi();

        throw_unless($attachment->embedded instanceof MessageBuilder, LogicException::class, 'Embedded attachments require an embedded message builder.');

        $streams = [];
        $timestamp = new DateTimeImmutable('now');
        $values = [
            'attachMethod'         => self::ATTACH_METHOD_EMBEDDED_MESSAGE,
            'attachNum'            => $attachNum,
            'creationTime'         => self::unixToFiletime($timestamp),
            'lastModificationTime' => self::unixToFiletime($timestamp),
            'objectType'           => self::OBJECT_TYPE_ATTACH,
            'renderingPosition'    => 0xFFFFFFFF,
            'storeSupportMask'     => self::STORE_SUPPORT_MASK,
        ];
        $streams += self::encodeBinaryProperty('0FF6', random_bytes(4));
        $streams += self::encodeBinaryProperty('0FF9', random_bytes(16));

        if ($attachment->extension !== null) {
            $streams += self::encodeStringProperty('3703', $attachment->extension);
        }

        if ($attachment->fileName !== null) {
            $streams += self::encodeStringProperty('3704', $attachment->fileName);
            $streams += self::encodeStringProperty('3707', $attachment->fileName);
        }

        if ($attachment->mimeType !== null) {
            $streams += self::encodeStringProperty('370e', $attachment->mimeType);
        }

        if ($attachment->language !== null) {
            $streams += self::encodeStringProperty('3A0C', $attachment->language);
        }

        if ($attachment->displayName !== null) {
            $streams += self::encodeStringProperty('3001', $attachment->displayName);
        }

        if ($attachment->contentId !== null) {
            $streams += self::encodeStringProperty('3712', $attachment->contentId);
        }

        if ($attachment->isInline) {
            $values['attachFlags'] = self::ATTACH_FLAG_RENDERED_IN_BODY;
        }

        $storage = self::buildStorageStreams(
            Properties::$attachmentProperties,
            $values,
            $streams,
            false,
        );

        $attachDataObject = pack('V', (0x3701 << 16) | 0x000D).pack('VVV', 0, 0, 0);

        return self::appendRawProperties($storage, $attachment->rawProperties)
            ->appendProperties($attachDataObject);
    }

    /**
     * @param array<string, string> $streams
     * @param RecipientPayload[]    $recipients
     */
    private static function addDisplayRecipients(array &$streams, string $propertyId, array $recipients, int $type): void
    {
        $display = array_values(array_map(
            static fn (RecipientPayload $recipient): string => $recipient->display() ?? '',
            array_filter($recipients, static fn (RecipientPayload $recipient): bool => $recipient->type === $type),
        ));

        if ($display === []) {
            $streams += self::encodeEmptyStringProperty($propertyId);

            return;
        }

        $streams += self::encodeStringPropertyWithoutTerminator($propertyId, implode(';', $display));
    }

    /**
     * Encodes raw (unknown) MAPI properties into the property stream binary fragment.
     * Fixed-size types are encoded inline; variable-size types produce stream entries
     * that callers must add to the compound file separately.
     *
     * @param RawProperty[]         $rawProperties
     * @param array<string, string> $existingStreams
     */
    private static function buildRawPropertyBinary(array $rawProperties, array &$existingStreams): string
    {
        $binary = '';

        foreach ($rawProperties as $property) {
            $type = PropertyTypes::get($property->typeId);
            if (! $type instanceof PropertyType) {
                continue;
            }

            $propertyId = (int) hexdec($property->id);
            $propertyTag = ($propertyId << 16) | $property->typeId;

            if ($type->size !== null && ! $type->multi) {
                $binary .= pack('V', $propertyTag);
                $binary .= pack('V', $property->flags);
                $binary .= self::encodePropertyValue($type, $property->value);

                continue;
            }

            $raw = is_string($property->value) ? $property->value : '';
            if ($type === PropertyTypes::$PtypString) {
                $raw = self::encodeUnicodeStringWithoutTerminator($raw);
            }

            $typeHex = strtoupper(str_pad(dechex($property->typeId), 4, '0', STR_PAD_LEFT));
            $streamName = sprintf(
                '__substg1.0_%s%s',
                strtoupper(str_pad($property->id, 4, '0', STR_PAD_LEFT)),
                $typeHex,
            );

            if (isset($existingStreams[$streamName])) {
                continue;
            }

            $binary .= pack('V', $propertyTag);
            $binary .= pack('V', $property->flags);
            $binary .= pack('V', self::propertyStreamLength($type, $raw));
            $binary .= pack('V', 0);
            $existingStreams[$streamName] = $raw;
        }

        return $binary;
    }

    /**
     * @param RawProperty[] $rawProperties
     */
    private static function appendRawProperties(StorageStreams $storage, array $rawProperties): StorageStreams
    {
        $streams = $storage->streams;
        $properties = self::buildRawPropertyBinary($rawProperties, $streams);

        return new StorageStreams($storage->properties.$properties, $streams);
    }

    /**
     * @param PropertyDefinition[]  $definitions
     * @param array<string, mixed>  $values
     * @param array<string, string> $streamValues
     */
    private static function buildStorageStreams(
        array $definitions,
        array $values,
        array $streamValues,
        bool $isRoot,
        int $recipientCount = 0,
        int $attachmentCount = 0,
        int $subStorageSize = 0,
    ): StorageStreams {
        $properties = $isRoot
            ? self::rootPropertyHeader($recipientCount, $attachmentCount)
            : str_repeat("\0", 8);

        $streams = [];
        $variableSize = 0;

        foreach ($definitions as $definition) {
            $name = $definition->name;

            if ($definition->source === PropertySource::Property) {
                if (! array_key_exists($name, $values)) {
                    continue;
                }

                $properties .= self::encodeFixedProperty($definition, $values[$name]);

                continue;
            }

            $key = strtolower($definition->id);
            if (! array_key_exists($key, $streamValues)) {
                continue;
            }

            $data = $streamValues[$key];
            $type = $definition->types[0];
            $streams[self::streamNameFor($definition, $type)] = $data;
            $length = self::propertyStreamLength($type, $data);
            $properties .= self::encodeStreamProperty($definition, $length);
            $variableSize += $length;
            unset($streamValues[$key]);
        }

        if ($isRoot) {
            $properties .= self::encodeFixedProperty(
                new PropertyDefinition(
                    '0E08',
                    'messageSize',
                    [PropertyTypes::$PtypInteger32],
                    PropertySource::Property,
                    0x00000006,
                ),
                $subStorageSize + $variableSize + 8,
            );
        }

        return new StorageStreams($properties, $streams);
    }

    private static function rootPropertyHeader(int $recipientCount, int $attachmentCount): string
    {
        return str_repeat("\0", 8)
            .pack('V', $recipientCount)
            .pack('V', $attachmentCount)
            .pack('V', $recipientCount)
            .pack('V', $attachmentCount)
            .str_repeat("\0", 8);
    }

    private static function encodeFixedProperty(PropertyDefinition $definition, mixed $value): string
    {
        $type = $definition->types[0];
        $propertyTag = (hexdec($definition->id) << 16) | $type->id;

        return pack('V', $propertyTag)
            .pack('V', $definition->flags)
            .self::encodePropertyValue($type, $value);
    }

    private static function encodeStreamProperty(PropertyDefinition $definition, int $length): string
    {
        $type = $definition->types[0];
        $propertyTag = (hexdec($definition->id) << 16) | $type->id;

        return pack('V', $propertyTag)
            .pack('V', $definition->flags)
            .pack('V', $length)
            .pack('V', 0);
    }

    private static function propertyStreamLength(PropertyType $type, string $data): int
    {
        return match ($type) {
            PropertyTypes::$PtypString  => strlen($data) + 2,
            PropertyTypes::$PtypString8 => strlen($data) + 1,
            default                     => strlen($data),
        };
    }

    private static function encodePropertyValue(PropertyType $type, mixed $value): string
    {
        return match ($type) {
            PropertyTypes::$PtypInteger16  => pack('v', is_int($value) ? $value : 0).str_repeat("\0", 6),
            PropertyTypes::$PtypInteger32  => pack('V', is_int($value) ? $value : 0).pack('V', 0),
            PropertyTypes::$PtypFloating32 => (is_int($value) ? pack('V', $value) : pack('g', is_float($value) ? $value : 0)).pack('V', 0),
            PropertyTypes::$PtypFloating64,
            PropertyTypes::$PtypFloatingTime => $value instanceof BigInteger
                ? self::encodeUInt64($value)
                : pack('e', is_float($value) || is_int($value) ? $value : 0),
            PropertyTypes::$PtypBoolean => pack('V', (int) ((bool) $value)).pack('V', 0),
            PropertyTypes::$PtypCurrency,
            PropertyTypes::$PtypTime,
            PropertyTypes::$PtypInteger64 => self::encodeUInt64(
                $value instanceof BigInteger || is_int($value) || is_string($value) ? $value : 0
            ),
            PropertyTypes::$PtypErrorCode => pack('V', is_int($value) ? $value : 0).pack('V', 0),
            default                       => pack('V', is_int($value) ? $value : 0).pack('V', 0),
        };
    }

    /**
     * @return array<string, string>
     */
    private static function encodeStringProperty(string $id, string $value): array
    {
        return [strtolower($id) => self::encodeUnicodeStringWithoutTerminator($value)];
    }

    /**
     * @return array<string, string>
     */
    private static function encodeStringPropertyWithoutTerminator(string $id, string $value): array
    {
        return [strtolower($id) => self::encodeUnicodeStringWithoutTerminator($value)];
    }

    /**
     * @return array<string, string>
     */
    private static function encodeEmptyStringProperty(string $id): array
    {
        return [strtolower($id) => ''];
    }

    /**
     * @return array<string, string>
     */
    private static function encodeBinaryProperty(string $id, string $value): array
    {
        return [strtolower($id) => $value];
    }

    private static function encodeUnicodeString(string $value): string
    {
        return mb_convert_encoding($value."\0", 'UTF-16LE', 'UTF-8');
    }

    private static function encodeUnicodeStringWithoutTerminator(string $value): string
    {
        return mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
    }

    private static function streamNameFor(PropertyDefinition $definition, PropertyType $type): string
    {
        return self::streamName($definition->id, $type);
    }

    private static function streamName(string $id, PropertyType $type): string
    {
        return sprintf(
            '__substg1.0_%s%s',
            strtoupper(str_pad($id, 4, '0', STR_PAD_LEFT)),
            strtoupper(str_pad(dechex($type->id), 4, '0', STR_PAD_LEFT)),
        );
    }

    private static function encodeUInt64(BigInteger|string|int $value): string
    {
        $bigInteger = $value instanceof BigInteger ? $value : BigInteger::of($value);
        $low = $bigInteger->mod(1 << 32)->toInt();
        $high = $bigInteger->shiftedRight(32)->toInt();

        return pack('V', $low).pack('V', $high);
    }

    private static function randomUuidUtf16(): string
    {
        return self::encodeUnicodeStringWithoutTerminator(self::uuidV4());
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s%s%s-%s%s-%s%s-%s%s-%s%s%s%s%s%s', str_split(bin2hex($bytes), 2));
    }

    private static function recipientSearchKey(string $email): string
    {
        return strtoupper(self::SENDER_ADDRESS_TYPE).':'.strtoupper($email)."\0";
    }

    private static function oneOffEntryId(string $email, string $displayName): string
    {
        return pack('V', 0)
            .self::ONE_OFF_ENTRY_ID_PROVIDER_UID
            .pack('v', 0)
            .pack('v', 0x8001)
            .self::encodeUnicodeString($displayName)
            .self::encodeUnicodeString(self::SENDER_ADDRESS_TYPE)
            .self::encodeUnicodeString($email);
    }

    /**
     * @return array{string, string}
     */
    private static function splitSubject(?string $subject): array
    {
        if ($subject === null || $subject === '') {
            return ['', ''];
        }

        if (preg_match('/^(\D{1,3}:\s)(.*)$/u', $subject, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return ['', $subject];
    }

    private static function unixToFiletime(DateTimeImmutable $date): BigInteger
    {
        $seconds = BigInteger::of((int) $date->format('U'));
        $microseconds = BigInteger::of((int) $date->format('u'));

        return $seconds
            ->plus(self::filetimeOffsetSeconds())
            ->multipliedBy(10_000_000)
            ->plus($microseconds->multipliedBy(10));
    }

    private static function filetimeOffsetSeconds(): BigInteger
    {
        return BigInteger::of(11644473600);
    }

    private static function bootMapi(): void
    {
        Properties::init();
        PropertyTypes::init();
    }
}
