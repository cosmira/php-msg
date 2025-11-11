<?php

declare(strict_types=1);

namespace MsgViewer\Writer;

final class AttachmentPayload
{
    public function __construct(
        public ?string $fileName = null,
        public ?string $displayName = null,
        public ?string $mimeType = null,
        public ?string $language = null,
        public ?string $extension = null,
        public string $content = ''
    ) {}
}
