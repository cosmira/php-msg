<?php

declare(strict_types=1);

namespace MsgViewer\Mapi;

final class PropertyStreamEntry
{
    /**
     * @param array<string, PropertyData> $data
     */
    public function __construct(
        public readonly PropertyHeader $header,
        public readonly array $data
    ) {}
}
