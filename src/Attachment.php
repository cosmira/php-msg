<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

final readonly class Attachment
{
    /**
     * @param RawProperty[] $rawProperties MAPI properties not mapped to named fields
     */
    public function __construct(
        public ?string $extension,
        public ?string $fileName,
        public ?string $mimeType,
        public ?string $language,
        public ?string $displayName,
        public ?string $content,
        public ?Message $embedded,
        public ?string $contentId = null,
        public bool $isInline = false,
        public array $rawProperties = [],
    ) {}

    /** @return RawProperty[] */
    public function getRawProperties(): array
    {
        return $this->rawProperties;
    }
}
