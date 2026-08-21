<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Support\BinarySource;
use RuntimeException;

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
        string|BinarySource $sourceBinary,
        array $preservedAttachmentIndexes = [],
        array $preservedRecipientIndexes = [],
    ): void {
        $buffer = is_string($sourceBinary) ? new BinaryBuffer($sourceBinary) : $sourceBinary->buffer();
        throw_unless($buffer instanceof BinaryBuffer, RuntimeException::class, 'The source message does not provide stable random access.');
        $source = CompoundFile::fromBinary($buffer);
        $root = $source->directory->root();
        $hasRoot = $root instanceof DirectoryEntry;

        if (! $hasRoot) {
            return;
        }

        $merge = new CompoundStorageMerge(
            $target,
            $source,
            array_fill_keys($preservedAttachmentIndexes, true),
            array_fill_keys($preservedRecipientIndexes, true),
        );

        $merge->merge($root);
    }
}
