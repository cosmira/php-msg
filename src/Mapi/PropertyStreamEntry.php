<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

final readonly class PropertyStreamEntry
{
    /**
     * @param array<string, PropertyData> $data
     */
    public function __construct(
        public PropertyHeader $header,
        public array $data
    ) {}
}
