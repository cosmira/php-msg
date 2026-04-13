<?php

declare(strict_types=1);

namespace MsgViewer\Mapi;

final readonly class EntryStreamData
{
    public function __construct(
        public int $nameIdOrStringOffset,
        public int $propertyIndex,
        public int $guidIndex,
        public PropertyKind $propertyKind
    ) {}
}
