<?php

declare(strict_types=1);

namespace MsgViewer\Writer;

use Brick\Math\BigInteger;
use DateTimeImmutable;
use MsgViewer\Mapi\Properties;
use MsgViewer\Mapi\PropertyDefinition;
use MsgViewer\Mapi\PropertySource;
use MsgViewer\Mapi\PropertyType;
use MsgViewer\Mapi\PropertyTypes;
use MsgViewer\RawProperty;

final class MessageWriter
{
    private const CODEPAGE = 65001;

    private const MESSAGE_CLASS = 'IPM.Note';

    public static function make(MessageBuilder $builder): string
    {
        Properties::init();
        PropertyTypes::init();

        $compound = new CompoundBuilder();
        self::populateMsgStorage($compound, $compound->rootIndex(), $builder, true);

        return $compound->build();
    }

    /** @deprecated Use make() */
    public static function write(MessageBuilder $builder): string
    {
        return self::make($builder);
    }

    /**
     * Recursively populates a MSG storage (root or embedded) in the compound file.
     */
    private static function populateMsgStorage(
        CompoundBuilder $compound,
        int $parentIndex,
        MessageBuilder $builder,
        bool $isRoot,
    ): void {
        $recipientCount = count($builder->recipients());
        $attachmentCount = count($builder->attachments());

        $rootStreams = self::buildRootPropertyStreams($builder, $recipientCount, $attachmentCount);

        $compound->addStream('__properties_version1.0', $rootStreams['propertyStream'], $parentIndex);
        foreach ($rootStreams['streams'] as $name => $data) {
            $compound->addStream($name, $data, $parentIndex);
        }

        // MS-OXMSG §2.2.3 requires three empty streams in __nameid_version1.0 for root MSG only.
        if ($isRoot) {
            $nameidIndex = $compound->addStorage('__nameid_version1.0', $parentIndex);
            $compound->addStream('__substg1.0_00020102', '', $nameidIndex);
            $compound->addStream('__substg1.0_00030102', '', $nameidIndex);
            $compound->addStream('__substg1.0_00040102', '', $nameidIndex);
        }

        foreach ($builder->recipients() as $i => $recipient) {
            $storageName = sprintf('__recip_version1.0_#%08X', $i);
            $storageIndex = $compound->addStorage($storageName, $parentIndex);

            $recipientStreams = self::buildRecipientStreams($recipient);
            $compound->addStream('__properties_version1.0', $recipientStreams['propertyStream'], $storageIndex);
            foreach ($recipientStreams['streams'] as $name => $data) {
                $compound->addStream($name, $data, $storageIndex);
            }
        }

        foreach ($builder->attachments() as $i => $attachment) {
            $storageName = sprintf('__attach_version1.0_#%08X', $i);
            $storageIndex = $compound->addStorage($storageName, $parentIndex);

            if ($attachment->embedded !== null) {
                self::buildEmbeddedMsgAttachment($compound, $storageIndex, $attachment);
            } else {
                $attachmentStreams = self::buildAttachmentStreams($attachment);
                $compound->addStream('__properties_version1.0', $attachmentStreams['propertyStream'], $storageIndex);
                foreach ($attachmentStreams['streams'] as $name => $data) {
                    $compound->addStream($name, $data, $storageIndex);
                }
            }
        }
    }

    /**
     * Builds an embedded .msg attachment storage (ATTACH_EMBEDDED_MSG, method 5).
     * The embedded message is placed in a sub-storage named __substg1.0_3701000D.
     */
    private static function buildEmbeddedMsgAttachment(
        CompoundBuilder $compound,
        int $attachStorageIndex,
        AttachmentPayload $attachment,
    ): void {
        assert($attachment->embedded !== null);

        $streams = [];
        $values = ['attachMethod' => 5]; // ATTACH_EMBEDDED_MSG

        if ($attachment->extension !== null) {
            $streams += self::encodeStringProperty('3703', $attachment->extension);
        }

        if ($attachment->fileName !== null) {
            $streams += self::encodeStringProperty('3707', $attachment->fileName);
        }

        if ($attachment->mimeType !== null) {
            $streams += self::encodeStringProperty('370e', $attachment->mimeType);
        }

        if ($attachment->displayName !== null) {
            $streams += self::encodeStringProperty('3001', $attachment->displayName);
        }

        // Build the property stream — 3701 entries are intentionally omitted from streams
        // so buildPropertyStream skips them; we append the object property entry manually.
        $propertyStream = self::buildPropertyStream(
            Properties::$attachmentProperties,
            $values,
            $streams,
            false,
        );

        // Append PR_ATTACH_DATA_OBJ (0x3701/0x000D) — OLE object, size 0 per spec.
        $binary = $propertyStream['binary'];
        $binary .= pack('V', (0x3701 << 16) | 0x000D);
        $binary .= pack('VVV', 0, 0, 0);

        $compound->addStream('__properties_version1.0', $binary, $attachStorageIndex);
        foreach ($propertyStream['streams'] as $name => $data) {
            $compound->addStream($name, $data, $attachStorageIndex);
        }

        // Recursively populate the embedded MSG in a sub-storage.
        $embeddedStorageIndex = $compound->addStorage('__substg1.0_3701000D', $attachStorageIndex);
        self::populateMsgStorage($compound, $embeddedStorageIndex, $attachment->embedded, false);
    }

    /**
     * @return array{propertyStream: string, streams: array<string, string>}
     */
    private static function buildRootPropertyStreams(MessageBuilder $builder, int $recipientCount, int $attachmentCount): array
    {
        $values = [];
        $streams = [];

        $values['codepage'] = self::CODEPAGE;

        if ($builder->date instanceof DateTimeImmutable) {
            $values['date'] = self::unixToFiletime($builder->date);
        }

        if ($builder->subject !== null) {
            $streams += self::encodeStringProperty('0037', $builder->subject);
        }

        if ($builder->senderName !== null) {
            $streams += self::encodeStringProperty('0c1a', $builder->senderName);
        }

        if ($builder->senderEmail !== null) {
            $streams += self::encodeStringProperty('0c1f', $builder->senderEmail);
        }

        if ($builder->body !== null) {
            $streams += self::encodeStringProperty('1000', $builder->body);
        }

        if ($builder->bodyHtml !== null) {
            $streams += self::encodeStringProperty('1013', $builder->bodyHtml);
        }

        if ($builder->bodyRtf !== null) {
            $streams += self::encodeBinaryProperty('1009', $builder->bodyRtf);
        }

        if ($builder->headers !== null) {
            $streams += self::encodeStringProperty('007d', $builder->headers);
        }

        // Build PR_DISPLAY_TO/CC/BCC grouped by recipient type; use email ?? name fallback.
        $toRecipients  = array_filter($builder->recipients(), static fn (RecipientPayload $r): bool => $r->type === RecipientPayload::TO);
        $ccRecipients  = array_filter($builder->recipients(), static fn (RecipientPayload $r): bool => $r->type === RecipientPayload::CC);
        $bccRecipients = array_filter($builder->recipients(), static fn (RecipientPayload $r): bool => $r->type === RecipientPayload::BCC);

        if ($toRecipients !== []) {
            $toDisplay = array_map(static fn (RecipientPayload $r): string => $r->email ?? $r->name ?? '', $toRecipients);
            $streams += self::encodeStringProperty('0E04', implode(';', $toDisplay));
        }

        if ($ccRecipients !== []) {
            $ccDisplay = array_map(static fn (RecipientPayload $r): string => $r->email ?? $r->name ?? '', $ccRecipients);
            $streams += self::encodeStringProperty('0E03', implode(';', $ccDisplay));
        }

        if ($bccRecipients !== []) {
            $bccDisplay = array_map(static fn (RecipientPayload $r): string => $r->email ?? $r->name ?? '', $bccRecipients);
            $streams += self::encodeStringProperty('0E02', implode(';', $bccDisplay));
        }

        $streams += self::encodeStringProperty('001A', self::MESSAGE_CLASS);

        // Encode raw (unknown) properties from the builder.
        $rawBinary = self::buildRawPropertyBinary($builder->getRawProperties(), $streams);

        $definitions = array_merge(
            [
                new PropertyDefinition(
                    '001A',
                    'messageClass',
                    [PropertyTypes::$PtypString],
                    PropertySource::Stream
                ),
            ],
            Properties::$rootProperties,
            [Properties::$codepageProperty]
        );

        $propertyStream = self::buildPropertyStream(
            $definitions,
            $values,
            $streams,
            true,
            $recipientCount,
            $attachmentCount
        );

        return [
            'propertyStream' => $propertyStream['binary'].$rawBinary,
            'streams'        => $propertyStream['streams'],
        ];
    }

    /**
     * @return array{propertyStream: string, streams: array<string, string>}
     */
    private static function buildRecipientStreams(RecipientPayload $recipient): array
    {
        $streams = [];
        $values = ['type' => $recipient->type];

        if ($recipient->name !== null) {
            $streams += self::encodeStringProperty('3001', $recipient->name);
        }

        if ($recipient->email !== null) {
            $streams += self::encodeStringProperty('39fe', $recipient->email);
        }

        $propertyStream = self::buildPropertyStream(
            Properties::$recipientProperties,
            $values,
            $streams,
            false
        );

        return [
            'propertyStream' => $propertyStream['binary'].self::buildRawPropertyBinary($recipient->rawProperties, $streams),
            'streams'        => $propertyStream['streams'],
        ];
    }

    /**
     * @return array{propertyStream: string, streams: array<string, string>}
     */
    private static function buildAttachmentStreams(AttachmentPayload $attachment): array
    {
        $streams = [];
        $values = [];

        if ($attachment->extension !== null) {
            $streams += self::encodeStringProperty('3703', $attachment->extension);
        }

        if ($attachment->fileName !== null) {
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
            $values['attachFlags'] = 0x04; // ATT_MHTML_REF
        }

        $propertyStream = self::buildPropertyStream(
            Properties::$attachmentProperties,
            $values,
            $streams,
            false
        );

        return [
            'propertyStream' => $propertyStream['binary'].self::buildRawPropertyBinary($attachment->rawProperties, $streams),
            'streams'        => $propertyStream['streams'],
        ];
    }

    /**
     * Encodes raw (unknown) MAPI properties into the property stream binary fragment.
     * Fixed-size types are encoded inline; variable-size types produce stream entries
     * that callers must add to the compound file separately.
     *
     * @param  RawProperty[]         $rawProps
     * @param  array<string, string> $existingStreams  Already-encoded streams; raw stream props are appended here.
     * @return string  Binary fragment ready to append to the property stream.
     */
    private static function buildRawPropertyBinary(array $rawProps, array &$existingStreams): string
    {
        $binary = '';

        foreach ($rawProps as $prop) {
            $type = PropertyTypes::get($prop->typeId);
            if ($type === null) {
                continue;
            }

            $propId = (int) hexdec($prop->id);
            $propertyTag = ($propId << 16) | $prop->typeId;

            if ($type->size !== null && !$type->multi) {
                // Fixed-size property
                $binary .= pack('V', $propertyTag);
                $binary .= pack('V', $prop->flags);
                $binary .= self::encodePropertyValue($type, $prop->value);
            } else {
                // Variable-size: write as stream
                $raw = is_string($prop->value) ? $prop->value : '';
                $typeHex = str_pad(dechex($prop->typeId), 4, '0', STR_PAD_LEFT);
                $streamName = sprintf('__substg1.0_%s%s', str_pad($prop->id, 4, '0', STR_PAD_LEFT), $typeHex);

                if (isset($existingStreams[$streamName])) {
                    continue; // Don't overwrite a known property stream
                }

                $binary .= pack('V', $propertyTag);
                $binary .= pack('V', $prop->flags);
                $binary .= pack('V', strlen($raw));
                $binary .= pack('V', 0);
                $existingStreams[$streamName] = $raw;
            }
        }

        return $binary;
    }

    /**
     * @param PropertyDefinition[]  $definitions
     * @param array<string, mixed>  $values
     * @param array<string, string> $streams
     *
     * @return array{binary: string, streams: array<string, string>}
     */
    private static function buildPropertyStream(
        array $definitions,
        array $values,
        array $streamValues,
        bool $isRoot,
        int $recipientCount = 0,
        int $attachmentCount = 0
    ): array {
        $binary = '';
        if ($isRoot) {
            $binary .= str_repeat("\0", 8);
            $binary .= pack('V', $recipientCount);
            $binary .= pack('V', $attachmentCount);
            $binary .= pack('V', $recipientCount);
            $binary .= pack('V', $attachmentCount);
            $binary .= str_repeat("\0", 8);
        } else {
            $binary .= str_repeat("\0", 8);
        }

        $resultStreams = [];

        foreach ($definitions as $definition) {
            $name = $definition->name;
            if ($definition->source === PropertySource::Property) {
                if (! array_key_exists($name, $values)) {
                    continue;
                }

                $binary .= self::encodeFixedProperty($definition, $values[$name]);
            } else {
                $key = strtolower($definition->id);
                if (! array_key_exists($key, $streamValues)) {
                    continue;
                }

                $data = $streamValues[$key];
                $streamName = self::streamNameFor($definition, $definition->types[0]);
                $binary .= self::encodeStreamProperty($definition, strlen((string) $data));
                $resultStreams[$streamName] = $data;
                unset($streamValues[$key]);
            }
        }

        return ['binary' => $binary, 'streams' => $resultStreams];
    }

    private static function encodeFixedProperty(PropertyDefinition $definition, mixed $value): string
    {
        $type = $definition->types[0];
        $propertyTag = (hexdec($definition->id) << 16) | $type->id;

        $buffer = pack('V', $propertyTag);
        $buffer .= pack('V', 0);

        return $buffer . self::encodePropertyValue($type, $value);
    }

    private static function encodeStreamProperty(PropertyDefinition $definition, int $length): string
    {
        $type = $definition->types[0];
        $propertyTag = (hexdec($definition->id) << 16) | $type->id;
        $buffer = pack('V', $propertyTag);
        $buffer .= pack('V', 0);
        $buffer .= pack('V', $length);

        return $buffer . pack('V', 0);
    }

    private static function encodePropertyValue(PropertyType $type, mixed $value): string
    {
        return match ($type) {
            PropertyTypes::$PtypInteger32 => pack('V', (int) $value).pack('V', 0),
            PropertyTypes::$PtypTime      => self::encodeUInt64($value),
            default                       => pack('V', (int) $value).pack('V', 0),
        };
    }

    /**
     * @return array<string, string>
     */
    private static function encodeStringProperty(string $id, string $value): array
    {
        return [
            strtolower($id) => self::encodeUnicodeString($value),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function encodeBinaryProperty(string $id, string $value): array
    {
        return [
            strtolower($id) => $value,
        ];
    }

    private static function encodeUnicodeString(string $value): string
    {
        return mb_convert_encoding($value."\0", 'UTF-16LE', 'UTF-8');
    }

    private static function streamNameFor(PropertyDefinition $definition, PropertyType $type): string
    {
        return self::streamName($definition->id, $type);
    }

    private static function streamName(string $id, PropertyType $type): string
    {
        return sprintf(
            '__substg1.0_%s%s',
            strtolower(str_pad($id, 4, '0', STR_PAD_LEFT)),
            strtolower(str_pad(dechex($type->id), 4, '0', STR_PAD_LEFT))
        );
    }

    private static function encodeUInt64(BigInteger|string|int $value): string
    {
        $big = $value instanceof BigInteger ? $value : BigInteger::of($value);
        $low = $big->mod(1 << 32)->toInt();
        $high = $big->shiftedRight(32)->toInt();

        return pack('V', $low).pack('V', $high);
    }

    private static function unixToFiletime(DateTimeImmutable $date): BigInteger
    {
        $seconds = BigInteger::of((int) $date->format('U'));
        $microseconds = BigInteger::of((int) $date->format('u'));

        $base = $seconds->plus(self::filetimeOffsetSeconds())->multipliedBy(10_000_000);

        return $base->plus($microseconds->multipliedBy(10));
    }

    private static function filetimeOffsetSeconds(): BigInteger
    {
        return BigInteger::of(11644473600);
    }
}
