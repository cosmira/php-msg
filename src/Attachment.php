<?php

declare(strict_types=1);

namespace MsgViewer;

final class Attachment
{
    public function __construct(
        public readonly ?string $extension,
        public readonly ?string $fileName,
        public readonly ?string $mimeType,
        public readonly ?string $language,
        public readonly ?string $displayName,
        public readonly ?string $content,
        public readonly ?Message $embedded
    ) {}
}
