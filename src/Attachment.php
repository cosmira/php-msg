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

    /**
     * Get the file extension for the attachment, when available.
     */
    public function extension(): ?string
    {
        return $this->extension;
    }

    /**
     * Get the file name for the attachment.
     */
    public function fileName(): ?string
    {
        return $this->fileName;
    }

    /**
     * Get the MIME type associated with the attachment.
     */
    public function mimeType(): ?string
    {
        return $this->mimeType;
    }

    /**
     * Get the language metadata for the attachment.
     */
    public function language(): ?string
    {
        return $this->language;
    }

    /**
     * Get the display name shown for the attachment.
     */
    public function displayName(): ?string
    {
        return $this->displayName;
    }

    /**
     * Get the attachment payload for regular file attachments.
     */
    public function content(): ?string
    {
        return $this->content;
    }

    /**
     * Get the embedded message when this attachment is a nested MSG.
     */
    public function embedded(): ?Message
    {
        return $this->embedded;
    }

    /**
     * Get the Content-ID used for inline attachments.
     */
    public function contentId(): ?string
    {
        return $this->contentId;
    }

    /**
     * Determine whether the attachment should be rendered inline.
     */
    public function isInline(): bool
    {
        return $this->isInline;
    }

    /**
     * Get the raw MAPI properties that were not mapped to named fields.
     *
     * @return RawProperty[]
     */
    public function rawProperties(): array
    {
        return $this->rawProperties;
    }

    /**
     * Get the raw MAPI properties that were not mapped to named fields.
     *
     * @return RawProperty[]
     */
    public function getRawProperties(): array
    {
        return $this->rawProperties();
    }
}
