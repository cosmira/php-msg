<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Brick\Math\BigInteger;
use Cosmira\OutlookMessage\CompoundFile\Directory\ColorFlag;
use Cosmira\OutlookMessage\CompoundFile\Directory\ObjectType;

final class DirectoryEntryData
{
    /**
     * The directory indexes of direct children owned by this storage.
     *
     * @var list<int>
     */
    public array $children = [];

    /**
     * The directory identifier of the left sibling, or the no-stream sentinel.
     */
    public int $leftSiblingId = CompoundFileBuilder::NO_STREAM;

    /**
     * The directory identifier of the right sibling, or the no-stream sentinel.
     */
    public int $rightSiblingId = CompoundFileBuilder::NO_STREAM;

    /**
     * The identifier of this storage's first child in the sibling tree.
     */
    public int $childId = CompoundFileBuilder::NO_STREAM;

    /**
     * The first sector containing this entry's stream data.
     */
    public int $startingSector = 0;

    /**
     * The stream byte length represented as a 64-bit integer.
     */
    public BigInteger $streamSize;

    /**
     * Initialize a serializable directory entry with an empty stream.
     */
    public function __construct(
        /**
         * The compound-file directory name encoded when the record is serialized.
         */
        public string $name,
        /**
         * The compound-file object type represented by this entry.
         */
        public ObjectType $type,
        /**
         * The red-black sibling-tree color recorded in the directory entry.
         */
        public ColorFlag $color,
    ) {
        $this->streamSize = BigInteger::zero();
    }

    /**
     * Serialize a directory entry into its 128-byte compound-file record.
     */
    public function serialize(): string
    {
        $utf16 = mb_convert_encoding($this->name, 'UTF-16LE', 'UTF-8');
        $utf16 = mb_strcut($utf16, 0, 62, 'UTF-16LE')."\0\0";

        $rawLength = strlen($utf16);
        $utf16 = str_pad($utf16, 64, "\0");
        $clsid = $this->type === ObjectType::RootStorage
            ? "\x0B\x0D\x02\x00\x00\x00\x00\x00\xC0\x00\x00\x00\x00\x00\x00\x46"
            : str_repeat("\0", 16);
        $size = $this->streamSize->isLessThan(0) ? BigInteger::zero() : $this->streamSize;
        $low = $size->mod(1 << 32)->toInt();
        $high = $size->shiftedRight(32)->toInt();
        $streamSize = pack('V', $low).pack('V', $high);
        $buffer = $utf16
            .pack('v', $rawLength)
            .chr($this->type->value)
            .chr($this->color->value)
            .pack('V3', $this->leftSiblingId, $this->rightSiblingId, $this->childId)
            .$clsid
            .str_repeat("\0", 20)
            .pack('V', $this->startingSector)
            .$streamSize;

        return str_pad($buffer, 128, "\0");
    }
}
