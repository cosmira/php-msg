<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

final readonly class Recipient
{
    /**
     * @param RawProperty[] $rawProperties MAPI properties not mapped to named fields
     */
    public function __construct(
        public ?string $name,
        public ?string $email,
        public ?int $type = null,
        public array $rawProperties = [],
    ) {}

    /** @return RawProperty[] */
    public function getRawProperties(): array
    {
        return $this->rawProperties;
    }
}
