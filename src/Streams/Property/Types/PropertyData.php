<?php

declare(strict_types=1);

namespace MsgViewer\Streams\Property\Types;

use Brick\Math\BigInteger;
use MsgViewer\Streams\Property\PropertyType;

final class PropertyData
{
    public function __construct(
        public readonly PropertyType $propertyType,
        public readonly int $propertyId,
        public readonly int $flags,
        /** @var int|BigInteger */
        public readonly int|BigInteger $valueOrSize
    ) {
    }
}

