<?php

declare(strict_types=1);

namespace MsgViewer\Mapi;

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
