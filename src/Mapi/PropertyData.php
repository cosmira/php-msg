<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

use Brick\Math\BigInteger;

final readonly class PropertyData
{
    /**
     * Create a decoded MAPI property record.
     */
    public function __construct(
        /**
         * The decoded MAPI type for the property.
         */
        public PropertyType $propertyType,
        /**
         * The numeric MAPI property identifier.
         */
        public int $propertyId,
        /**
         * The access flags stored with the property.
         */
        public int $flags,
        /**
         * The inline value or external stream size.
         *
         * @var int|BigInteger
         */
        public int|BigInteger $valueOrSize
    ) {}
}
