<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\AttachmentMethod;
use Cosmira\OutlookMessage\Mapi\Properties;
use Cosmira\OutlookMessage\Mapi\PropertyDefinition;
use Cosmira\OutlookMessage\Mapi\PropertySource;
use Cosmira\OutlookMessage\Mapi\PropertyType;
use Cosmira\OutlookMessage\Mapi\PropertyTypes;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Recipient;
use Cosmira\OutlookMessage\Rtf\RtfCompressor;
use DateTimeImmutable;
use LogicException;

final class MapiStorageEncoder
{
    private const CODEPAGE = 65001;

    private const MESSAGE_FLAGS_UNMODIFIED = 0x0002;

    private const MESSAGE_FLAGS_UNSENT = 0x0008;

    private const MESSAGE_FLAGS_HAS_ATTACH = 0x0010;

    private const OBJECT_TYPE_MESSAGE = 5;

    private const ICON_INDEX_UNSENT_MAIL = 0x00000103;

    private const ICON_INDEX_NEW_MAIL = 0x00000000;

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

    private const ATTACH_FLAG_RENDERED_IN_BODY = 0x04;

    private const OBJECT_TYPE_ATTACH = 7;

    /**
     * Encode the root MAPI streams for the given message builder.
     */
    public static function forMessage(MessageBuilder $builder, int $subStorageSize = 0): StorageStreams
    {
        self::bootMapi();

        $values = self::messageValues($builder);
        $streams = self::messageStreams($builder);
        $definitions = array_merge(Properties::$rootProperties, [Properties::$codepageProperty]);

        $storage = self::buildStorageStreams(
            $definitions,
            $values,
            $streams,
            true,
            count($builder->recipients()),
            count($builder->attachments()),
            $subStorageSize,
        );

        return self::appendRawProperties($storage, $builder->rawProperties(), $builder->codepage);
    }

    /** @return array<string, mixed> */
    private static function messageValues(MessageBuilder $builder): array
    {
        $timestamp = $builder->date ?? new DateTimeImmutable('now');
        $hasAttachments = $builder->attachments() !== [];
        $flags = self::MESSAGE_FLAGS_UNMODIFIED;
        $flags |= $builder->draft ? self::MESSAGE_FLAGS_UNSENT : 0;
        $flags |= $hasAttachments ? self::MESSAGE_FLAGS_HAS_ATTACH : 0;
        $values = [
            'access'                    => self::ACCESS_READ_WRITE_DELETE,
            'accessLevel'               => self::ACCESS_LEVEL_MODIFY,
            'alternateRecipientAllowed' => 1,
            'creationTime'              => self::unixToFiletime($timestamp),
            'deleteAfterSubmit'         => 0,
            'hasAttach'                 => $hasAttachments ? 1 : 0,
            'lastModificationTime'      => self::unixToFiletime($timestamp),
            'messageFlags'              => $flags,
            'objectType'                => self::OBJECT_TYPE_MESSAGE,
            'readReceiptRequested'      => $builder->readReceiptRequested ? 1 : 0,
            'rtfInSync'                 => $builder->bodyRtf !== null ? 1 : 0,
            'storeSupportMask'          => self::STORE_SUPPORT_MASK,
            'storeUnicodeMask'          => self::STORE_SUPPORT_MASK,
        ];

        self::addOptionalMessageValues($values, $builder);

        return $values;
    }

    /** @param array<string, mixed> $values */
    private static function addOptionalMessageValues(array &$values, MessageBuilder $builder): void
    {
        $defaults = $builder->shouldWriteMissingMetadataDefaults();

        if (self::shouldWriteOptional($builder->importance, $defaults)) {
            $values['importance'] = $builder->importance ?? self::IMPORTANCE_NORMAL;
        }

        if (self::shouldWriteOptional($builder->priority, $defaults)) {
            $values['priority'] = $builder->priority ?? self::PRIORITY_NONURGENT;
        }

        if (self::shouldWriteOptional($builder->iconIndex, $defaults)) {
            $values['iconIndex'] = $builder->iconIndex ?? ($builder->draft ? self::ICON_INDEX_UNSENT_MAIL : self::ICON_INDEX_NEW_MAIL);
        }

        if (self::shouldWriteOptional($builder->messageLocaleId, $defaults)) {
            $values['messageLocaleId'] = $builder->messageLocaleId ?? self::MESSAGE_LOCALE_ID;
        }

        if (self::shouldWriteOptional($builder->codepage, $defaults)) {
            $values['codepage'] = $builder->codepage ?? self::CODEPAGE;
        }

        if ($builder->date instanceof DateTimeImmutable) {
            $values['clientSubmitTime'] = self::unixToFiletime($builder->date);
        }

        if ($builder->receivedAt instanceof DateTimeImmutable) {
            $values['date'] = self::unixToFiletime($builder->receivedAt);
        }

        if ($builder->editorFormat !== null) {
            $values['messageEditorFormat'] = $builder->editorFormat;
        }
    }

    private static function shouldWriteOptional(mixed $value, bool $defaults): bool
    {
        return $value !== null || $defaults;
    }

    /** @return array<string, string> */
    private static function messageStreams(MessageBuilder $builder): array
    {
        $streams = self::encodeBinaryProperty('300B', random_bytes(16));
        self::addSubjectStreams($streams, $builder);
        $streams += self::encodeEmptyStringProperty('0050');
        self::addSenderStreams($streams, $builder);
        self::addContentStreams($streams, $builder);
        self::addInternetStreams($streams, $builder);
        self::addDisplayRecipients($streams, '0E04', $builder->recipients(), Recipient::TYPE_TO);
        self::addDisplayRecipients($streams, '0E03', $builder->recipients(), Recipient::TYPE_CC);
        self::addDisplayRecipients($streams, '0E02', $builder->recipients(), Recipient::TYPE_BCC);

        return $streams + self::encodeStringProperty('001A', $builder->messageClass);
    }

    /** @param array<string, string> $streams */
    private static function addSubjectStreams(array &$streams, MessageBuilder $builder): void
    {
        if ($builder->subject === null) {
            return;
        }

        [$prefix, $normalized] = self::splitSubject($builder->subject);
        $streams += self::encodeStringProperty('0037', $builder->subject);
        $streams += $prefix === '' ? self::encodeEmptyStringProperty('003D') : self::encodeStringProperty('003D', $prefix);

        $hasConversationTopic = $builder->conversationTopic !== null || $builder->shouldDeriveConversationTopic();

        if ($hasConversationTopic) {
            $streams += self::encodeStringProperty('0070', $builder->conversationTopic ?? $normalized);
        }

        $streams += self::encodeStringProperty('0E1D', $normalized);
    }

    /** @param array<string, string> $streams */
    private static function addSenderStreams(array &$streams, MessageBuilder $builder): void
    {
        $hasNameOnly = $builder->senderName !== null && $builder->senderEmail === null;

        if ($hasNameOnly) {
            $streams += self::encodeStringPropertyWithoutTerminator('0c1a', $builder->senderName);
        }

        if ($builder->senderEmail !== null) {
            $display = $builder->senderName ?? $builder->senderEmail;
            $streams += self::encodeBinaryProperty('0c19', self::oneOffEntryId($builder->senderEmail, $display));
            $shouldWriteName = $builder->senderName !== null || $builder->shouldWriteMissingMetadataDefaults();

            if ($shouldWriteName) {
                $streams += self::encodeStringPropertyWithoutTerminator('0c1a', $display);
            }

            $streams += self::encodeStringPropertyWithoutTerminator('0c1e', self::SENDER_ADDRESS_TYPE);
            $streams += self::encodeStringPropertyWithoutTerminator('0c1f', $builder->senderEmail);
            $streams += self::encodeStringPropertyWithoutTerminator('4022', self::SENDER_ADDRESS_TYPE);
            $streams += self::encodeStringPropertyWithoutTerminator('4023', $builder->senderEmail);
            $streams += self::encodeStringPropertyWithoutTerminator('4038', $display);
        }

        if ($builder->representingName !== null) {
            $streams += self::encodeStringPropertyWithoutTerminator('0042', $builder->representingName);
        }

        if ($builder->representingEmail !== null) {
            $streams += self::encodeStringPropertyWithoutTerminator('0064', self::SENDER_ADDRESS_TYPE);
            $streams += self::encodeStringPropertyWithoutTerminator('0065', $builder->representingEmail);
        }
    }

    /** @param array<string, string> $streams */
    private static function addContentStreams(array &$streams, MessageBuilder $builder): void
    {
        if ($builder->body !== null) {
            $streams += $builder->body === '' ? self::encodeEmptyStringProperty('1000') : self::encodeStringProperty('1000', $builder->body);
        }

        if ($builder->bodyHtml !== null) {
            $streams += self::encodeBinaryProperty('1013', $builder->bodyHtml);
        }

        if ($builder->bodyRtf !== null) {
            $streams += self::encodeBinaryProperty('1009', $builder->bodyRtfCompressed ?? RtfCompressor::wrapUncompressed($builder->bodyRtf));
        }
    }

    /** @param array<string, string> $streams */
    private static function addInternetStreams(array &$streams, MessageBuilder $builder): void
    {
        if ($builder->headers !== null) {
            $streams += self::encodeStringProperty('007d', $builder->headers);
        }

        if ($builder->internetMessageId !== null) {
            $streams += self::encodeStringProperty('1035', $builder->internetMessageId);
        }

        if ($builder->messageSubmissionId !== null) {
            $streams += self::encodeBinaryProperty('0047', $builder->messageSubmissionId);
        }

        if ($builder->internetReferences !== null) {
            $streams += self::encodeStringProperty('1039', $builder->internetReferences);
        }

        if ($builder->inReplyToId !== null) {
            $streams += self::encodeStringProperty('1042', $builder->inReplyToId);
        }
    }

    /**
     * Encode the MAPI streams for the given recipient row.
     */
    public static function forRecipient(
        RecipientPayload $recipient,
        int $rowId = 0,
        ?int $codepage = null,
    ): StorageStreams {
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

        return self::appendRawProperties($storage, $recipient->rawProperties, $codepage);
    }

    /**
     * Encode the MAPI streams for a by-value attachment.
     */
    public static function forAttachment(
        Attachment $attachment,
        int $attachNum = 0,
        ?int $codepage = null,
    ): StorageStreams {
        self::bootMapi();

        throw_unless(
            $attachment->method() === AttachmentMethod::ByValue,
            LogicException::class,
            'Regular attachments require the by-value attachment method.',
        );

        $streams = [];
        $timestamp = new DateTimeImmutable('now');
        $recordKey = random_bytes(16);
        $renderingPosition = AttachmentStorageMetadata::renderingPosition($attachment);
        $values = [
            'attachMethod'         => AttachmentMethod::ByValue->value,
            'attachNum'            => $attachNum,
            'attachSize'           => strlen($attachment->data()),
            'creationTime'         => self::unixToFiletime($timestamp),
            'instanceKey'          => null,
            'lastModificationTime' => self::unixToFiletime($timestamp),
            'objectType'           => self::OBJECT_TYPE_ATTACH,
            'renderingPosition'    => $renderingPosition ?? 0xFFFFFFFF,
            'storeSupportMask'     => self::STORE_SUPPORT_MASK,
        ];
        $streams += self::encodeBinaryProperty('0FF6', random_bytes(4));
        $streams += self::encodeBinaryProperty('0FF9', $recordKey);

        self::addAttachmentStreams($streams, $attachment);

        $streams += self::encodeBinaryProperty('3701', $attachment->data());

        $isInline = $attachment->isInline();

        if ($isInline) {
            $values['attachFlags'] = self::ATTACH_FLAG_RENDERED_IN_BODY;
        }

        $storage = self::buildStorageStreams(
            Properties::$attachmentProperties,
            $values,
            $streams,
            false,
        );

        return self::appendRawProperties($storage, $attachment->rawProperties(), $codepage);
    }

    /**
     * Encode the MAPI streams for an embedded-message attachment.
     */
    public static function forEmbeddedAttachment(
        Attachment $attachment,
        int $attachNum = 0,
        ?int $codepage = null,
    ): StorageStreams {
        self::bootMapi();

        throw_unless($attachment->message() instanceof Message, LogicException::class, 'Embedded attachments require an embedded message.');
        throw_unless(
            $attachment->method() === AttachmentMethod::EmbeddedMessage,
            LogicException::class,
            'Embedded attachments require the embedded-message attachment method.',
        );

        $streams = [];
        $timestamp = new DateTimeImmutable('now');
        $renderingPosition = AttachmentStorageMetadata::renderingPosition($attachment);
        $values = [
            'attachMethod'         => AttachmentMethod::EmbeddedMessage->value,
            'attachNum'            => $attachNum,
            'creationTime'         => self::unixToFiletime($timestamp),
            'lastModificationTime' => self::unixToFiletime($timestamp),
            'objectType'           => self::OBJECT_TYPE_ATTACH,
            'renderingPosition'    => $renderingPosition ?? 0xFFFFFFFF,
            'storeSupportMask'     => self::STORE_SUPPORT_MASK,
        ];
        $streams += self::encodeBinaryProperty('0FF6', random_bytes(4));
        $streams += self::encodeBinaryProperty('0FF9', random_bytes(16));

        self::addAttachmentStreams($streams, $attachment);

        $isInline = $attachment->isInline();

        if ($isInline) {
            $values['attachFlags'] = self::ATTACH_FLAG_RENDERED_IN_BODY;
        }

        $storage = self::buildStorageStreams(
            Properties::$attachmentProperties,
            $values,
            $streams,
            false,
        );

        $attachDataObject = pack('V', (0x3701 << 16) | 0x000D).pack('VVV', 0, 0, 0);

        return self::appendRawProperties($storage, $attachment->rawProperties(), $codepage)
            ->appendProperties($attachDataObject);
    }

    /** @param array<string, string> $streams */
    private static function addAttachmentStreams(array &$streams, Attachment $attachment): void
    {
        $extension = $attachment->extension();
        $name = $attachment->name();
        $mime = $attachment->mime();
        $language = $attachment->language();
        $displayName = $attachment->displayName();
        $contentId = $attachment->contentId();

        if ($extension !== null) {
            $streams += self::encodeStringProperty('3703', $extension);
        }

        if ($name !== null) {
            $streams += self::encodeStringProperty('3704', $name);
            $streams += self::encodeStringProperty('3707', $name);
        }

        if ($mime !== null) {
            $streams += self::encodeStringProperty('370e', $mime);
        }

        if ($language !== null) {
            $streams += self::encodeStringProperty('3A0C', $language);
        }

        if ($displayName !== null) {
            $streams += self::encodeStringProperty('3001', $displayName);
        }

        if ($contentId !== null) {
            $streams += self::encodeStringProperty('3712', $contentId);
        }
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
    private static function buildRawPropertyBinary(
        array $rawProperties,
        array &$existingStreams,
        ?int $codepage = null,
    ): string {
        $binary = '';

        foreach ($rawProperties as $property) {
            $type = PropertyTypes::get($property->typeId);
            $hasKnownType = $type instanceof PropertyType;

            if (! $hasKnownType) {
                continue;
            }

            $propertyId = (int) hexdec($property->id);
            $propertyTag = ($propertyId << 16) | $property->typeId;

            $isFixed = $type->size !== null && ! $type->multi;

            if ($isFixed) {
                $binary .= pack('V', $propertyTag);
                $binary .= pack('V', $property->flags);
                $binary .= self::encodePropertyValue($type, $property->value);

                continue;
            }

            $raw = is_string($property->value) ? $property->value : '';
            if ($type === PropertyTypes::$PtypString) {
                $raw = self::encodeUnicodeStringWithoutTerminator($raw);
            }

            if ($type === PropertyTypes::$PtypString8) {
                $raw = self::encodeAnsiStringWithoutTerminator($raw, $codepage);
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
    private static function appendRawProperties(
        StorageStreams $storage,
        array $rawProperties,
        ?int $codepage = null,
    ): StorageStreams {
        $streams = $storage->streams;
        $properties = self::buildRawPropertyBinary($rawProperties, $streams, $codepage);

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
                $hasValue = array_key_exists($name, $values);

                if (! $hasValue) {
                    continue;
                }

                $properties .= self::encodeFixedProperty($definition, $values[$name]);

                continue;
            }

            $key = strtolower($definition->id);
            $hasStream = array_key_exists($key, $streamValues);

            if (! $hasStream) {
                continue;
            }

            $data = $streamValues[$key];
            $type = current($definition->types);
            $hasType = $type instanceof PropertyType;

            if (! $hasType) {
                continue;
            }

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
        return PropertyValueEncoder::encode($type, $value);
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

    private static function encodeAnsiStringWithoutTerminator(string $value, ?int $codepage): string
    {
        $encoding = Properties::$codepages[$codepage ?? 1252] ?? 'windows-1252';

        return mb_convert_encoding($value, $encoding, 'UTF-8');
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

    private static function randomUuidUtf16(): string
    {
        return self::encodeUnicodeStringWithoutTerminator(self::uuidV4());
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $version = chr((ord(substr($bytes, 6, 1)) & 0x0F) | 0x40);
        $variant = chr((ord(substr($bytes, 8, 1)) & 0x3F) | 0x80);
        $bytes = substr_replace($bytes, $version, 6, 1);
        $bytes = substr_replace($bytes, $variant, 8, 1);

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
        $isEmpty = $subject === null || $subject === '';

        if ($isEmpty) {
            return ['', ''];
        }

        $hasPrefix = preg_match('/^(?P<prefix>\D{1,3}:\s)(?P<subject>.*)$/u', $subject, $matches) === 1;

        if ($hasPrefix) {
            return [$matches['prefix'], $matches['subject']];
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
