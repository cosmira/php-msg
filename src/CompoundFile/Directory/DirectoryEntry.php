<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\CompoundFile\Directory;

use Brick\Math\BigInteger;

final readonly class DirectoryEntry
{
    /**
     * Create a compound-file directory entry.
     */
    public function __construct(
        /**
         * The decoded directory entry name.
         */
        public string $entryName,
        /**
         * The encoded UTF-16 name length in bytes.
         */
        public int $entryNameLength,
        /**
         * The compound-file object type.
         */
        public ObjectType $objectType,
        /**
         * The red-black tree color flag.
         */
        public ColorFlag $colorFlag,
        /**
         * The index of the left sibling entry.
         */
        public int $leftSiblingId,
        /**
         * The index of the right sibling entry.
         */
        public int $rightSiblingId,
        /**
         * The index of the first child entry.
         */
        public int $childId,
        /**
         * The storage class identifier encoded as hexadecimal.
         */
        public string $clsid,
        /**
         * The application-defined state flags.
         */
        public int $stateBits,
        /**
         * The storage creation FILETIME value.
         */
        public BigInteger $creationTime,
        /**
         * The storage modification FILETIME value.
         */
        public BigInteger $modifiedTime,
        /**
         * The first sector used by the stream.
         */
        public int $startingSectorLocation,
        /**
         * The stream length in bytes.
         */
        public BigInteger $streamSize
    ) {}
}
