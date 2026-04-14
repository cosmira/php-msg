<?php

declare(strict_types=1);

namespace MsgViewer\Writer;

use MsgViewer\RawProperty;

final class AttachmentPayload
{
    public static function file(string $fileName, string $content = '', ?string $displayName = null): self
    {
        return new self(
            fileName: $fileName,
            displayName: $displayName ?? $fileName,
            content: $content,
        );
    }

    public static function inline(
        string $fileName,
        string $content,
        ?string $contentId = null,
        ?string $displayName = null,
    ): self {
        return new self(
            fileName: $fileName,
            displayName: $displayName ?? $fileName,
            content: $content,
            contentId: $contentId,
            isInline: true,
        );
    }

    public static function embedded(MessageBuilder $message, string $displayName = 'message.msg'): self
    {
        return new self(
            fileName: $displayName,
            displayName: $displayName,
            embedded: $message,
        );
    }

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

    public function isEmbedded(): bool
    {
        return $this->embedded instanceof MessageBuilder;
    }
}
