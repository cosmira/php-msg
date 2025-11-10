<?php

declare(strict_types=1);

namespace MsgViewer\Streams\Property;

/**
 * @param PropertyType[] $types
 */
final class PropertyDefinition
{
    /**
     * @param PropertyType[] $types
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $types,
        public readonly PropertySource $source
    ) {
    }
}

