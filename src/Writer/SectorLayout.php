<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

/** @internal */
final readonly class SectorLayout
{
    /**
     * @param list<int> $fat
     * @param list<int> $difat
     */
    public function __construct(
        public array $fat,
        public array $difat,
    ) {}
}
