<?php

declare(strict_types=1);

namespace MsgViewer\Writer;

use LogicException;

final class MessageWriter
{
    public static function make(MessageBuilder $builder): string
    {
        $compound = new CompoundBuilder();
        self::writeStorage($compound, $compound->rootIndex(), $builder, true);

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
    private static function writeStorage(
        CompoundBuilder $compound,
        int $storageIndex,
        MessageBuilder $builder,
        bool $isRoot,
    ): void {
        MapiStorageEncoder::forMessage($builder)->writeTo($compound, $storageIndex);

        if ($isRoot) {
            self::writeNameIdStorage($compound, $storageIndex);
        }

        foreach ($builder->recipients() as $i => $recipient) {
            self::writeRecipientStorage($compound, $storageIndex, $recipient, $i);
        }

        foreach ($builder->attachments() as $i => $attachment) {
            self::writeAttachmentStorage($compound, $storageIndex, $attachment, $i);
        }
    }

    private static function writeNameIdStorage(CompoundBuilder $compound, int $parentIndex): void
    {
        $nameIdIndex = $compound->addStorage('__nameid_version1.0', $parentIndex);
        $compound->addStream('__substg1.0_00020102', '', $nameIdIndex);
        $compound->addStream('__substg1.0_00030102', '', $nameIdIndex);
        $compound->addStream('__substg1.0_00040102', '', $nameIdIndex);
    }

    private static function writeRecipientStorage(
        CompoundBuilder $compound,
        int $parentIndex,
        RecipientPayload $recipient,
        int $index,
    ): void {
        $storageIndex = $compound->addStorage(
            sprintf('__recip_version1.0_#%08X', $index),
            $parentIndex,
        );

        MapiStorageEncoder::forRecipient($recipient)->writeTo($compound, $storageIndex);
    }

    private static function writeAttachmentStorage(
        CompoundBuilder $compound,
        int $parentIndex,
        AttachmentPayload $attachment,
        int $index,
    ): void {
        $storageIndex = $compound->addStorage(
            sprintf('__attach_version1.0_#%08X', $index),
            $parentIndex,
        );

        if ($attachment->isEmbedded()) {
            self::writeEmbeddedAttachment($compound, $storageIndex, $attachment);

            return;
        }

        MapiStorageEncoder::forAttachment($attachment)->writeTo($compound, $storageIndex);
    }

    private static function writeEmbeddedAttachment(
        CompoundBuilder $compound,
        int $storageIndex,
        AttachmentPayload $attachment,
    ): void {
        throw_unless($attachment->embedded instanceof MessageBuilder, LogicException::class, 'Embedded attachments require an embedded message builder.');

        MapiStorageEncoder::forEmbeddedAttachment($attachment)->writeTo($compound, $storageIndex);

        $embeddedStorageIndex = $compound->addStorage('__substg1.0_3701000D', $storageIndex);
        self::writeStorage($compound, $embeddedStorageIndex, $attachment->embedded, false);
    }
}
