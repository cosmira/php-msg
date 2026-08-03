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
     */
    public static function mergeMissing(CompoundFileBuilder $target, string $sourceBinary): void
    {
        $source = CompoundFile::fromBinary(new BinaryBuffer($sourceBinary));
        $root = $source->directory->entries[0] ?? null;
        if (! $root instanceof DirectoryEntry) {
            return;
        }

        self::mergeChildren($target, $target->rootIndex(), $source, $root);
    }

    /**
     * Recursively copy missing child storages and streams beneath a parent entry.
     */
    private static function mergeChildren(
        CompoundFileBuilder $target,
        int $targetParent,
        CompoundFile $source,
        DirectoryEntry $sourceParent,
    ): void {
        foreach (self::childEntries($source, $sourceParent) as $entry) {
            if ($entry->objectType === ObjectType::Storage) {
                $targetStorage = $target->findStorage($entry->entryName, $targetParent);
                if ($targetStorage === null) {
                    if ($target->hasChild($entry->entryName, $targetParent)) {
                        continue;
                    }

                    $targetStorage = $target->addStorage($entry->entryName, $targetParent);
                }

                self::mergeChildren($target, $targetStorage, $source, $entry);

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
