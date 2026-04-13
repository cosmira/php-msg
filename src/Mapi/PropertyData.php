<?php

declare(strict_types=1);

namespace MsgViewer\Mapi;

use Brick\Math\BigInteger;

final readonly class PropertyData
{
    public function __construct(
        public PropertyType $propertyType,
        public int $propertyId,
        public int $flags,
        /** @var int|BigInteger */
        public int|BigInteger $valueOrSize
    ) {}
}
