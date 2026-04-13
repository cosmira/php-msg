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

final class MessageWriter
{
    private const CODEPAGE = 65001;
    private const MESSAGE_CLASS = 'IPM.Note';

    public static function make(MessageBuilder $builder): string
    {
        Properties::init();
        PropertyTypes::init();

        $compound = new CompoundBuilder();
        $rootIndex = $compound->rootIndex();

        $recipientCount = count($builder->recipients());
        $attachmentCount = count($builder->attachments());

        $rootStreams = self::buildRootPropertyStreams($builder, $recipientCount, $attachmentCount);

        $compound->addStream('__properties_version1.0', $rootStreams['propertyStream'], $rootIndex);
        foreach ($rootStreams['streams'] as $name => $data) {
            $compound->addStream($name, $data, $rootIndex);
        }

        foreach ($builder->recipients() as $i => $recipient) {
            $storageName = sprintf('__recip_version1.0_#%08X', $i);
            $storageIndex = $compound->addStorage($storageName, $rootIndex);

            $recipientStreams = self::buildRecipientStreams($recipient);
            $compound->addStream('__properties_version1.0', $recipientStreams['propertyStream'], $storageIndex);
            foreach ($recipientStreams['streams'] as $name => $data) {
                $compound->addStream($name, $data, $storageIndex);
            }
        }

        foreach ($builder->attachments() as $i => $attachment) {
            $storageName = sprintf('__attach_version1.0_#%08X', $i);
            $storageIndex = $compound->addStorage($storageName, $rootIndex);

            $attachmentStreams = self::buildAttachmentStreams($attachment);
            $compound->addStream('__properties_version1.0', $attachmentStreams['propertyStream'], $storageIndex);
            foreach ($attachmentStreams['streams'] as $name => $data) {
                $compound->addStream($name, $data, $storageIndex);
            }
        }

        return $compound->build();
    }

    public static function write(MessageBuilder $builder): string
    {
        return self::make($builder);
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

        if ($builder->recipients() !== []) {
            $to = array_filter($builder->recipients(), static fn (RecipientPayload $r): bool => $r->email !== null);
            if ($to !== []) {
                $toEmails = array_map(static fn (RecipientPayload $r): string => (string) $r->email, $to);
                $streams += self::encodeStringProperty('0E04', implode(';', $toEmails));
            }
        }

        $streams += self::encodeStringProperty('001A', self::MESSAGE_CLASS);

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
            'propertyStream' => $propertyStream['binary'],
            'streams'        => $propertyStream['streams'],
        ];
    }

    /**
     * @return array{propertyStream: string, streams: array<string, string>}
     */
    private static function buildRecipientStreams(RecipientPayload $recipient): array
    {
        $streams = [];

        if ($recipient->name !== null) {
            $streams += self::encodeStringProperty('3001', $recipient->name);
        }

        if ($recipient->email !== null) {
            $streams += self::encodeStringProperty('39fe', $recipient->email);
        }

        $propertyStream = self::buildPropertyStream(
            Properties::$recipientProperties,
            [],
            $streams,
            false
        );

        return [
            'propertyStream' => $propertyStream['binary'],
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

        $streams += self::encodeBinaryProperty('3701', $attachment->content);

        $propertyStream = self::buildPropertyStream(
            Properties::$attachmentProperties,
            $values,
            $streams,
            false
        );

        return [
            'propertyStream' => $propertyStream['binary'],
            'streams'        => $propertyStream['streams'],
        ];
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
                $binary .= self::encodeStreamProperty($definition, strlen($data));
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
        $buffer .= self::encodePropertyValue($type, $value);

        return $buffer;
    }

    private static function encodeStreamProperty(PropertyDefinition $definition, int $length): string
    {
        $type = $definition->types[0];
        $propertyTag = (hexdec($definition->id) << 16) | $type->id;
        $buffer = pack('V', $propertyTag);
        $buffer .= pack('V', 0);
        $buffer .= pack('V', $length);
        $buffer .= pack('V', 0);

        return $buffer;
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
