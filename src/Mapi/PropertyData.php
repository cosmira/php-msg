<?php

declare(strict_types=1);

namespace MsgViewer\Mapi;

use Brick\Math\BigInteger;

final class PropertyData
{
    public function __construct(
        public readonly PropertyType $propertyType,
        public readonly int $propertyId,
        public readonly int $flags,
        /** @var int|BigInteger */
        public readonly int|BigInteger $valueOrSize
    ) {}
}
