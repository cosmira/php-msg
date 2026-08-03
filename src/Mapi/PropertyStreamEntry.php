<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

final readonly class PropertyStreamEntry
{
    /**
     * Create a decoded MAPI property stream.
     *
     * @param array<string, PropertyData> $data
     */
    public function __construct(
        /**
         * The decoded header for the property stream.
         */
        public PropertyHeader $header,
        /**
         * The decoded properties keyed by hexadecimal identifier.
         */
        public array $data
    ) {}
}
