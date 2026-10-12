<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer\Allocation;

use Cosmira\OutlookMessage\Support\BinarySource;

/** @internal */
final readonly class SectorRange
{
    /**
     * Describe one contiguous chain and its optional stream payload.
     */
    public function __construct(
        /**
         * The first sector allocated to this chain.
         */
        public int $start,
        /**
         * The number of sectors reserved for this chain.
         */
        public int $count,
        /**
         * The repeatable payload copied into stream allocations.
         */
        public ?BinarySource $source = null,
    ) {}

    /**
     * Determine whether the cursor has passed the end of this allocation.
     */
    public function isPast(int $sector): bool
    {
        return $sector >= $this->start + $this->count;
    }

    /**
     * Return the following chain entry, or the end-of-chain marker.
     */
    public function successor(int $sector): int
    {
        $next = $sector + 1;

        return $this->isPast($next) ? 0xFFFFFFFE : $next;
    }
}
