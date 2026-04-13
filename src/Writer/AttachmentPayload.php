<?php

declare(strict_types=1);

namespace MsgViewer\Writer;

use MsgViewer\RawProperty;

final class AttachmentPayload
{
    /**
     * @param RawProperty[] $rawProperties Extra MAPI properties to write verbatim (round-trip).
     */
    public function __construct(
        public ?string $fileName = null,
        public ?string $displayName = null,
        public ?string $mimeType = null,
        public ?string $language = null,
        public ?string $extension = null,
        public string $content = '',
        /** Populate this instead of $content to write an embedded .msg attachment. */
        public ?MessageBuilder $embedded = null,
        public ?string $contentId = null,
        public bool $isInline = false,
        public array $rawProperties = [],
    ) {}
}
