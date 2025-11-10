<?php

declare(strict_types=1);

namespace MsgViewer\Streams\Property\Types;

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
