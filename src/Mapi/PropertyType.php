<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

final readonly class PropertyType
{
    /**
     * Create a MAPI property-type definition.
     */
    public function __construct(
        /**
         * The numeric MAPI property-type identifier.
         */
        public int $id,
        /**
         * The canonical MAPI property-type name.
         */
        public string $name,
        /**
         * The fixed width in bytes, or null for variable-width values.
         */
        public ?int $size,
        /**
         * Whether the property type stores multiple values.
         */
        public bool $multi
    ) {}
}
