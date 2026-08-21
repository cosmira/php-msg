<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\Support\BinarySource;
use LogicException;
use RuntimeException;

final class MessageWriter
{
    /**
     * Serialize the given message builder to Outlook MSG binary.
     */
    public static function make(MessageBuilder $builder): string
    {
        $sourceBinary = MessageStorageMetadata::forBuilder($builder);
        $canReuseSource = $sourceBinary !== null && MessageStorageMetadata::isUnchanged($builder);

        if ($canReuseSource) {
            return is_string($sourceBinary) ? $sourceBinary : $sourceBinary->contents();
        }

        $compound = new CompoundBuilder();
        self::writeStorage($compound, $compound->rootIndex(), $builder, true);
        if (is_string($sourceBinary)) {
            CompoundStorageMerger::mergeMissing(
                $compound,
                $sourceBinary,
                MessageStorageMetadata::unchangedAttachmentIndexes($builder),
                MessageStorageMetadata::unchangedRecipientIndexes($builder),
            );
        }

        return $compound->build();
    }

    /**
     * Stream the given message builder into a writable destination.
     *
     * @param resource $destination
     */
    public static function writeTo(MessageBuilder $builder, $destination): void
    {
        throw_unless(is_resource($destination), RuntimeException::class, 'Message destination must be a writable stream.');

        $sourceBinary = MessageStorageMetadata::forBuilder($builder);
        if ($sourceBinary !== null && MessageStorageMetadata::isUnchanged($builder)) {
            if ($sourceBinary instanceof BinarySource) {
                $sourceBinary->copyTo($destination);
            } else {
                self::writeAll($destination, $sourceBinary);
            }

            return;
        }

        $compound = new CompoundBuilder();
        self::writeStorage($compound, $compound->rootIndex(), $builder, true);
        if (is_string($sourceBinary)) {
            CompoundStorageMerger::mergeMissing(
                $compound,
                $sourceBinary,
                MessageStorageMetadata::unchangedAttachmentIndexes($builder),
                MessageStorageMetadata::unchangedRecipientIndexes($builder),
            );
        }

        $compound->buildTo($destination);
    }

    /**
     * Write every byte of a string to the destination stream.
     *
     * @param resource $destination
     */
    private static function writeAll($destination, string $contents): void
    {
        $offset = 0;
        while ($offset < strlen($contents)) {
            $written = fwrite($destination, substr($contents, $offset));
            throw_if($written === false || $written === 0, RuntimeException::class, 'Unable to write message output.');
            $offset += $written;
        }
    }

    /**
     * Write a message builder to Outlook MSG binary.
     *
     * @deprecated Use make().
     */
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
        $recipientStorages = array_map(
            static fn (RecipientPayload $recipient, int $index): StorageStreams => MapiStorageEncoder::forRecipient(
                $recipient,
                $index,
                $builder->codepage,
            ),
            $builder->recipients(),
            array_keys($builder->recipients()),
        );

        $attachmentStorages = array_map(
            static fn (Attachment $attachment, int $index): StorageStreams => $attachment->isEmbedded()
                ? MapiStorageEncoder::forEmbeddedAttachment($attachment, $index, $builder->codepage)
                : MapiStorageEncoder::forAttachment($attachment, $index, $builder->codepage),
            $builder->attachments(),
            array_keys($builder->attachments()),
        );

        $subStorageSize = array_sum(array_map(
            static fn (StorageStreams $storage): int => $storage->totalSize(),
            array_merge($recipientStorages, $attachmentStorages),
        )) + array_sum(array_map(
            static fn (Attachment $attachment): int => $attachment->isEmbedded() || ! $attachment->isStreamed()
                ? 0
                : $attachment->size(),
            $builder->attachments(),
        ));

        MapiStorageEncoder::forMessage($builder, $subStorageSize)->writeTo($compound, $storageIndex);

        if ($isRoot) {
            self::writeNameIdStorage($compound, $storageIndex, $builder);
        }

        foreach ($recipientStorages as $i => $recipientStorage) {
            self::writeRecipientStorage($compound, $storageIndex, $recipientStorage, $i);
        }

        foreach ($builder->attachments() as $i => $attachment) {
            self::writeAttachmentStorage($compound, $storageIndex, $attachmentStorages[$i], $attachment, $i);
        }
    }

    private static function writeNameIdStorage(
        CompoundBuilder $compound,
        int $parentIndex,
        MessageBuilder $builder,
    ): void {
        $nameIdIndex = $compound->addStorage('__nameid_version1.0', $parentIndex);
        $streams = $builder->nameIdStreams();
        foreach (['__substg1.0_00020102', '__substg1.0_00030102', '__substg1.0_00040102'] as $name) {
            $compound->addStream($name, $streams[$name] ?? '', $nameIdIndex);
        }
    }

    private static function writeRecipientStorage(
        CompoundBuilder $compound,
        int $parentIndex,
        StorageStreams $storage,
        int $index,
    ): void {
        $storageIndex = $compound->addStorage(
            sprintf('__recip_version1.0_#%08X', $index),
            $parentIndex,
        );

        $storage->writeTo($compound, $storageIndex);
    }

    private static function writeAttachmentStorage(
        CompoundBuilder $compound,
        int $parentIndex,
        StorageStreams $storage,
        Attachment $attachment,
        int $index,
    ): void {
        $storageIndex = $compound->addStorage(
            sprintf('__attach_version1.0_#%08X', $index),
            $parentIndex,
        );

        if ($attachment->isEmbedded()) {
            self::writeEmbeddedAttachment($compound, $storageIndex, $storage, $attachment);

            return;
        }

        $storage->writeTo($compound, $storageIndex);
        if (! $attachment->isStreamed()) {
            return;
        }

        $compound->addStreamSource(
            '__substg1.0_37010102',
            BinarySource::fromWriter(
                $attachment->size(),
                static function ($destination) use ($attachment): void {
                    $attachment->copyTo($destination);
                },
            ),
            $storageIndex,
        );
    }

    private static function writeEmbeddedAttachment(
        CompoundBuilder $compound,
        int $storageIndex,
        StorageStreams $storage,
        Attachment $attachment,
    ): void {
        $message = $attachment->message();
        throw_unless($message instanceof Message, LogicException::class, 'Embedded attachments require an embedded message.');

        $storage->writeTo($compound, $storageIndex);

        $embeddedStorageIndex = $compound->addStorage('__substg1.0_3701000D', $storageIndex);
        self::writeStorage($compound, $embeddedStorageIndex, $message->toBuilder(), false);
    }
}
