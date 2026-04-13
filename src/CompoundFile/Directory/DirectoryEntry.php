<?php

declare(strict_types=1);

namespace MsgViewer\CompoundFile\Directory;

use Brick\Math\BigInteger;

final class DirectoryEntry
{
    public function __construct(
        public readonly string $entryName,
        public readonly int $entryNameLength,
        public readonly ObjectType $objectType,
        public readonly ColorFlag $colorFlag,
        public readonly int $leftSiblingId,
        public readonly int $rightSiblingId,
        public readonly int $childId,
        public readonly string $clsid,
        public readonly int $stateBits,
        public readonly BigInteger $creationTime,
        public readonly BigInteger $modifiedTime,
        public readonly int $startingSectorLocation,
        public readonly BigInteger $streamSize
    ) {}
}
