<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\CompoundFile\Directory\ObjectType;
use Cosmira\OutlookMessage\Support\BinarySource;

/** @internal */
final readonly class CompoundStorageMerge
{
    /**
     * Bind generated and source storages with their retained entry indexes.
     *
     * @param array<int, true> $attachments
     * @param array<int, true> $recipients
     */
    public function __construct(
        /**
         * The generated compound file receiving opaque source data.
         */
        private CompoundFileBuilder $target,
        /**
         * The parsed compound file supplying preserved storage data.
         */
        private CompoundFile $source,
        /**
         * Attachment fingerprints or storage indexes retained for round-trip matching.
         */
        private array $attachments,
        /**
         * Recipient fingerprints or storage indexes retained for round-trip matching.
         */
        private array $recipients,
    ) {}

    /**
     * Copy opaque source storage contents into the newly generated target.
     */
    public function merge(DirectoryEntry $root): void
    {
        $this->mergeChildren($this->target->rootIndex(), $root, true);
    }

    /**
     * Merge direct source children while preserving generated managed entries.
     */
    private function mergeChildren(
        int $targetParent,
        DirectoryEntry $sourceParent,
        bool $topLevel = false,
    ): void {
        foreach ($this->childEntries($sourceParent) as $entry) {
            if ($entry->objectType === ObjectType::Storage) {
                $this->mergeStorage($entry, $targetParent, $topLevel);

                continue;
            }

            if ($entry->objectType === ObjectType::Stream) {
                $this->mergeStream($entry, $targetParent);
            }
        }
    }

    /**
     * Merge a source storage subtree into the corresponding target storage.
     */
    private function mergeStorage(DirectoryEntry $entry, int $targetParent, bool $topLevel): void
    {
        $targetStorage = $this->target->findStorage($entry->entryName, $targetParent);
        $isExcluded = $topLevel && $this->isManagedStorageExcluded(
            $entry->entryName,
            $targetStorage,
        );

        if ($isExcluded) {
            return;
        }

        if ($targetStorage === null) {
            $hasConflict = $this->target->hasChild($entry->entryName, $targetParent);

            if ($hasConflict) {
                return;
            }

            $targetStorage = $this->target->addStorage($entry->entryName, $targetParent);
        }

        $this->mergeChildren($targetStorage, $entry);
    }

    /**
     * Copy an opaque source stream unless the target already owns that name.
     */
    private function mergeStream(DirectoryEntry $entry, int $targetParent): void
    {
        if ($this->target->hasChild($entry->entryName, $targetParent)) {
            return;
        }

        $this->target->addStreamSource(
            $entry->entryName,
            BinarySource::fromWriter(
                $entry->streamSize->toInt(),
                function (mixed $destination) use ($entry): void {
                    $this->source->copyStreamTo($entry, $destination);
                },
            ),
            $targetParent,
        );
    }

    /**
     * Determine whether a managed source entry was intentionally replaced.
     */
    private function isManagedStorageExcluded(string $name, ?int $targetStorage): bool
    {
        $attachment = $this->managedStorageIndex($name, '__attach_version1.0_#');

        if ($attachment !== null) {
            return $targetStorage === null || ! isset($this->attachments[$attachment]);
        }

        $recipient = $this->managedStorageIndex($name, '__recip_version1.0_#');

        if ($recipient !== null) {
            return $targetStorage === null || ! isset($this->recipients[$recipient]);
        }

        return false;
    }

    /**
     * Read the numeric index encoded in a managed storage name.
     */
    private function managedStorageIndex(string $name, string $prefix): ?int
    {
        $hasPrefix = str_starts_with($name, $prefix);

        if (! $hasPrefix) {
            return null;
        }

        $suffix = substr($name, strlen($prefix));
        $hasValidLength = strlen($suffix) === 8;

        if (! $hasValidLength) {
            return null;
        }

        $isHexadecimal = ctype_xdigit($suffix);

        if (! $isHexadecimal) {
            return null;
        }

        return intval($suffix, 16);
    }

    /**
     * Collect direct children in the source storage's sibling tree.
     *
     * @return list<DirectoryEntry>
     */
    private function childEntries(DirectoryEntry $parent): array
    {
        $entries = [];
        $visited = [];
        $this->walkSiblingTree($parent->childId, $visited, $entries);

        return $entries;
    }

    /**
     * Traverse source directory siblings while avoiding revisited identifiers.
     *
     * @param array<int, true>     $visited
     * @param list<DirectoryEntry> $result
     */
    private function walkSiblingTree(int $entryId, array &$visited, array &$result): void
    {
        $isSpecial = $entryId < 0 || $entryId >= 0xFFFFFFFE;

        if ($isSpecial) {
            return;
        }

        if (isset($visited[$entryId])) {
            return;
        }

        $entry = $this->source->directory->entries[$entryId] ?? null;

        $hasEntry = $entry instanceof DirectoryEntry;

        if (! $hasEntry) {
            return;
        }

        $visited[$entryId] = true;
        $this->walkSiblingTree($entry->leftSiblingId, $visited, $result);
        $result[] = $entry;
        $this->walkSiblingTree($entry->rightSiblingId, $visited, $result);
    }
}
