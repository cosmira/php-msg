<?php

declare(strict_types=1);

namespace MsgViewer\CompoundFile\Directory;

use Brick\Math\BigInteger;

final readonly class DirectoryEntry
{
    public function __construct(
        public string $entryName,
        public int $entryNameLength,
        public ObjectType $objectType,
        public ColorFlag $colorFlag,
        public int $leftSiblingId,
        public int $rightSiblingId,
        public int $childId,
        public string $clsid,
        public int $stateBits,
        public BigInteger $creationTime,
        public BigInteger $modifiedTime,
        public int $startingSectorLocation,
        public BigInteger $streamSize
    ) {}
}
