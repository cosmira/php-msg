<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

/** @internal */
final readonly class SectorLayout
{
    /**
     * Retain the computed FAT and DIFAT sector identifiers.
     *
     * @param list<int> $fat
     * @param list<int> $difat
     */
    public function __construct(
        /**
         * The ordered identifiers of the file allocation table sectors.
         */
        public array $fat,
        /**
         * The ordered identifiers of the DIFAT continuation sectors.
         */
        public array $difat,
    ) {}
}
