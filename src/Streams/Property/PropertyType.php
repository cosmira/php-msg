<?php

declare(strict_types=1);

namespace MsgViewer\Streams\Property;

final class PropertyType
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $size,
        public readonly bool $multi
    ) {}
}
