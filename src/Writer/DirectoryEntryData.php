<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\CompoundFile\Directory\ColorFlag;
use Cosmira\OutlookMessage\CompoundFile\Directory\ObjectType;

final class DirectoryEntryData
{
    public int $leftSiblingId = CompoundFileBuilder::NO_STREAM;

    public int $rightSiblingId = CompoundFileBuilder::NO_STREAM;

    public int $childId = CompoundFileBuilder::NO_STREAM;

    public int $startingSector = 0;

    public BigInteger $streamSize;

    public function __construct(
        public string $name,
        public ObjectType $type,
        public ColorFlag $color,
    ) {
        $this->streamSize = BigInteger::zero();
    }

    public function serialize(): string
    {
        $utf16 = mb_convert_encoding($this->name, 'UTF-16LE', 'UTF-8');
        $utf16 = mb_strcut($utf16, 0, 62, 'UTF-16LE')."\0\0";

        $rawLength = strlen($utf16);
        $utf16 = str_pad($utf16, 64, "\0");
        $nameLength = pack('v', $rawLength);
        $left = pack('V', $this->leftSiblingId);
        $right = pack('V', $this->rightSiblingId);
        $child = pack('V', $this->childId);
        $clsid = $this->type === ObjectType::RootStorage
            ? "\x0B\x0D\x02\x00\x00\x00\x00\x00\xC0\x00\x00\x00\x00\x00\x00\x46"
            : str_repeat("\0", 16);
        $stateBits = pack('V', 0);
        $creationTime = str_repeat("\0", 8);
        $modifiedTime = str_repeat("\0", 8);
        $startingSector = pack('V', $this->startingSector);
        $size = $this->streamSize->isLessThan(0) ? BigInteger::zero() : $this->streamSize;
        $low = $size->mod(1 << 32)->toInt();
        $high = $size->shiftedRight(32)->toInt();
        $streamSize = pack('V', $low).pack('V', $high);
        $buffer = $utf16
            .$nameLength
            .chr($this->type->value)
            .chr($this->color->value)
            .$left
            .$right
            .$child
            .$clsid
            .$stateBits
            .$creationTime
            .$modifiedTime
            .$startingSector
            .$streamSize;

        return str_pad($buffer, 128, "\0");
    }
}
