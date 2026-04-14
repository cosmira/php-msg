<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

final readonly class PropertyType
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $size,
        public bool $multi
    ) {}
}
