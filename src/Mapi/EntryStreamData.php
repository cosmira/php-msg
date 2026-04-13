<?php

declare(strict_types=1);

namespace MsgViewer\Mapi;

final class EntryStreamData
{
    public function __construct(
        public readonly int $nameIdOrStringOffset,
        public readonly int $propertyIndex,
        public readonly int $guidIndex,
        public readonly PropertyKind $propertyKind
    ) {}
}
