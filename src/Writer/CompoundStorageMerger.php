<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\CompoundFile\Directory\ObjectType;
use Cosmira\OutlookMessage\Support\BinaryBuffer;

/**
 * @internal
 */
final class CompoundStorageMerger
{
    /**
     * Merge storage entries missing from a generated CFB file from its source payload.
     *
     * @param list<int> $preservedAttachmentIndexes
     * @param list<int> $preservedRecipientIndexes
     */
    public static function mergeMissing(
        CompoundFileBuilder $target,
        string $sourceBinary,
        array $preservedAttachmentIndexes = [],
        array $preservedRecipientIndexes = [],
    ): void {
        $source = CompoundFile::fromBinary(new BinaryBuffer($sourceBinary));
        $root = $source->directory->entries[0] ?? null;
        if (! $root instanceof DirectoryEntry) {
            return;
        }

        self::mergeChildren(
            $target,
            $target->rootIndex(),
            $source,
            $root,
            array_fill_keys($preservedAttachmentIndexes, true),
            array_fill_keys($preservedRecipientIndexes, true),
            true,
        );
    }

    /**
     * Recursively copy missing child storages and streams beneath a parent entry.
     *
     * @param array<int, true> $preservedAttachmentIndexes
     * @param array<int, true> $preservedRecipientIndexes
     */
    private static function mergeChildren(
        CompoundFileBuilder $target,
        int $targetParent,
        CompoundFile $source,
        DirectoryEntry $sourceParent,
        array $preservedAttachmentIndexes,
        array $preservedRecipientIndexes,
        bool $topLevel = false,
    ): void {
        foreach (self::childEntries($source, $sourceParent) as $entry) {
            if ($entry->objectType === ObjectType::Storage) {
                $targetStorage = $target->findStorage($entry->entryName, $targetParent);

                if ($topLevel && self::isManagedStorageExcluded(
                    $entry->entryName,
                    $targetStorage,
                    $preservedAttachmentIndexes,
                    $preservedRecipientIndexes,
                )) {
                    continue;
                }

                if ($targetStorage === null) {
                    if ($target->hasChild($entry->entryName, $targetParent)) {
                        continue;
                    }

                    $targetStorage = $target->addStorage($entry->entryName, $targetParent);
                }

                self::mergeChildren(
                    $target,
                    $targetStorage,
                    $source,
                    $entry,
                    $preservedAttachmentIndexes,
                    $preservedRecipientIndexes,
                );

                continue;
            }

            if ($entry->objectType !== ObjectType::Stream) {
                continue;
            }

            if ($target->hasChild($entry->entryName, $targetParent)) {
                continue;
            }

            $target->addStream(
                $entry->entryName,
                $source->readStreamToString($entry),
                $targetParent,
            );
        }
    }

    /**
     * @param array<int, true> $preservedAttachmentIndexes
     * @param array<int, true> $preservedRecipientIndexes
     */
    private static function isManagedStorageExcluded(
        string $name,
        ?int $targetStorage,
        array $preservedAttachmentIndexes,
        array $preservedRecipientIndexes,
    ): bool {
        $attachmentIndex = self::managedStorageIndex($name, '__attach_version1.0_#');
        if ($attachmentIndex !== null) {
            return $targetStorage === null || ! isset($preservedAttachmentIndexes[$attachmentIndex]);
        }

        $recipientIndex = self::managedStorageIndex($name, '__recip_version1.0_#');
        if ($recipientIndex !== null) {
            return $targetStorage === null || ! isset($preservedRecipientIndexes[$recipientIndex]);
        }

        return false;
    }

    private static function managedStorageIndex(string $name, string $prefix): ?int
    {
        if (! str_starts_with($name, $prefix)) {
            return null;
        }

        $suffix = substr($name, strlen($prefix));
        if (strlen($suffix) !== 8 || ! ctype_xdigit($suffix)) {
            return null;
        }

        return intval($suffix, 16);
    }

    /**
     * Return the direct children represented by a parent's CFB sibling tree.
     *
     * @return list<DirectoryEntry>
     */
    private static function childEntries(CompoundFile $source, DirectoryEntry $parent): array
    {
        $entries = [];
        $visited = [];
        self::walkSiblingTree($source, $parent->childId, $visited, $entries);

        return $entries;
    }

    /**
     * Walk a CFB sibling tree in directory order without following child storages.
     *
     * @param array<int, true>     $visited
     * @param list<DirectoryEntry> $result
     */
    private static function walkSiblingTree(
        CompoundFile $source,
        int $entryId,
        array &$visited,
        array &$result,
    ): void {
        if ($entryId < 0 || $entryId >= 0xFFFFFFFE || isset($visited[$entryId])) {
            return;
        }

        $entry = $source->directory->entries[$entryId] ?? null;
        if (! $entry instanceof DirectoryEntry) {
            return;
        }

        $visited[$entryId] = true;
        self::walkSiblingTree($source, $entry->leftSiblingId, $visited, $result);
        $result[] = $entry;
        self::walkSiblingTree($source, $entry->rightSiblingId, $visited, $result);
    }
}
