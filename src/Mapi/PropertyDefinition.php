<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

/**
 * @param PropertyType[] $types
 */
final readonly class PropertyDefinition
{
    /**
     * @param PropertyType[] $types
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $types,
        public PropertySource $source
    ) {}
}
