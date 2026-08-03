<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

final readonly class EntryStreamData
{
    /**
     * Create a decoded MAPI entry-stream record.
     */
    public function __construct(
        /**
         * The numeric NameID or string-stream offset.
         */
        public int $nameIdOrStringOffset,
        /**
         * The property index assigned within the named range.
         */
        public int $propertyIndex,
        /**
         * The GUID-stream index and property-kind bit field.
         */
        public int $guidIndex,
        /**
         * Whether the named property uses a numeric or string identifier.
         */
        public PropertyKind $propertyKind
    ) {}
}
