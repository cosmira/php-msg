<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

/**
 * @param PropertyType[] $types
 */
final readonly class PropertyDefinition
{
    /**
     * Create a MAPI property definition.
     *
     * @param PropertyType[] $types
     */
    public function __construct(
        /**
         * The hexadecimal MAPI property identifier.
         */
        public string $id,
        /**
         * The internal field name used by the reader and writer.
         */
        public string $name,
        /**
         * The supported MAPI encodings for the property.
         */
        public array $types,
        /**
         * Whether the value is stored inline or in a stream.
         */
        public PropertySource $source,
        /**
         * The access flags written to the property stream.
         */
        public int $flags = 0,
    ) {}
}
