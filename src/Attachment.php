<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

use Closure;
use Cosmira\OutlookMessage\Exception\UnsupportedAttachmentMethodException;
use Cosmira\OutlookMessage\Support\BinarySource;
use RuntimeException;

final class Attachment
{
    /**
     * The mutation revision used to detect edits without reading the payload.
     */
    private int $revision = 0;

    /**
     * Create an attachment with its decoded MAPI fields.
     *
     * @param RawProperty[] $rawProperties MAPI properties not mapped to named fields
     */
    public function __construct(
        /**
         * The file extension, including its leading dot.
         */
        private ?string $extension = null,
        /**
         * The file name exposed to message consumers.
         */
        private ?string $fileName = null,
        /**
         * The MIME type associated with the attachment.
         */
        private ?string $mimeType = null,
        /**
         * The optional content language for the attachment.
         */
        private readonly ?string $language = null,
        /**
         * The human-readable attachment name.
         */
        private ?string $displayName = null,
        /**
         * The attachment payload or its lazy resolver.
         */
        private string|Closure|BinarySource|null $content = null,
        /**
         * The message contained by an embedded attachment.
         */
        private ?Message $embedded = null,
        /**
         * The identifier used to reference inline content.
         */
        private ?string $contentId = null,
        /**
         * Whether the attachment should render within the message body.
         */
        private bool $inline = false,
        /**
         * The unmapped MAPI properties preserved for the attachment.
         */
        private readonly array $rawProperties = [],
        /**
         * The MAPI method used to store the attachment.
         */
        private readonly ?AttachmentMethod $method = null,
    ) {}

    /**
     * Create a by-value attachment from the given data.
     */
    public static function fromData(string|Closure $data, ?string $name = null): self
    {
        return (new self(content: $data, method: AttachmentMethod::ByValue))->as($name);
    }

    /**
     * Create a lazily loaded attachment from the given file path.
     */
    public static function fromPath(string $path): self
    {
        return (new self(
            content: BinarySource::fromPath($path),
            method: AttachmentMethod::ByValue,
        ))->as(basename($path));
    }

    /**
     * Create an embedded attachment from the given message.
     */
    public static function fromMessage(Message $message, ?string $name = 'message.msg'): self
    {
        return (new self(embedded: $message, method: AttachmentMethod::EmbeddedMessage))->as($name);
    }

    /**
     * Resolve and return the attachment payload.
     */
    public function data(): string
    {
        return match ($this->method) {
            AttachmentMethod::ByValue         => $this->resolveContent(),
            AttachmentMethod::EmbeddedMessage => $this->embedded?->toBinary()
                ?? throw new RuntimeException('Embedded attachment has no message payload.'),
            default => throw UnsupportedAttachmentMethodException::for($this->method),
        };
    }

    /**
     * Get the exact byte length of the by-value attachment payload.
     */
    public function size(): int
    {
        if ($this->method !== AttachmentMethod::ByValue) {
            throw UnsupportedAttachmentMethodException::for($this->method);
        }

        return $this->source()->size();
    }

    /**
     * Copy the by-value attachment payload into the given writable stream.
     *
     * @param resource $destination
     */
    public function copyTo($destination): void
    {
        if ($this->method !== AttachmentMethod::ByValue) {
            throw UnsupportedAttachmentMethodException::for($this->method);
        }

        $this->source()->copyTo($destination);
    }

    /**
     * Calculate a digest of the by-value payload without retaining it in memory.
     */
    public function hash(string $algorithm = 'sha256'): string
    {
        if ($this->method !== AttachmentMethod::ByValue) {
            throw UnsupportedAttachmentMethodException::for($this->method);
        }

        return $this->source()->hash($algorithm);
    }

    /**
     * Determine whether the payload is retained as a streaming binary source.
     */
    public function isStreamed(): bool
    {
        return $this->content instanceof BinarySource;
    }

    /**
     * Replace the attachment payload.
     */
    public function withData(string|Closure $data): self
    {
        match ($this->method) {
            AttachmentMethod::ByValue         => $this->content = $data,
            AttachmentMethod::EmbeddedMessage => $this->embedded = Message::from($this->resolve($data)),
            default                           => throw UnsupportedAttachmentMethodException::for($this->method),
        };
        $this->revision++;

        return $this;
    }

    /**
     * Get the embedded message payload.
     */
    public function message(): ?Message
    {
        return $this->embedded;
    }

    /**
     * Replace the embedded message payload.
     */
    public function withMessage(Message $message): self
    {
        if ($this->method !== AttachmentMethod::EmbeddedMessage) {
            throw UnsupportedAttachmentMethodException::for($this->method);
        }

        $this->embedded = $message;
        $this->revision++;

        return $this;
    }

    /**
     * Set the file and display name for the attachment.
     */
    public function as(?string $name): self
    {
        if ($name === null) {
            return $this;
        }

        $this->fileName = $name;
        $this->displayName = $name;
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $this->extension = $extension === '' ? null : '.'.$extension;
        $this->revision++;

        return $this;
    }

    /**
     * Set the MIME type for the attachment.
     */
    public function withMime(?string $mime): self
    {
        $this->mimeType = $mime;
        $this->revision++;

        return $this;
    }

    /**
     * Mark the attachment as inline with an optional content ID.
     */
    public function inline(?string $contentId = null): self
    {
        $this->inline = true;
        $this->contentId = $contentId;
        $this->revision++;

        return $this;
    }

    /**
     * Get the preferred file name for the attachment.
     */
    public function name(): ?string
    {
        return $this->fileName ?? $this->displayName;
    }

    /**
     * Get the MIME type for the attachment.
     */
    public function mime(): ?string
    {
        return $this->mimeType;
    }

    /**
     * Get the content ID used to reference an inline attachment.
     */
    public function contentId(): ?string
    {
        return $this->contentId;
    }

    /**
     * Determine whether the attachment is rendered inline.
     */
    public function isInline(): bool
    {
        return $this->inline;
    }

    /**
     * Determine whether the attachment contains an embedded message.
     */
    public function isEmbedded(): bool
    {
        return $this->method === AttachmentMethod::EmbeddedMessage;
    }

    /**
     * Get the MAPI attachment method.
     */
    public function method(): ?AttachmentMethod
    {
        return $this->method;
    }

    /**
     * Get the file extension for the attachment.
     */
    public function extension(): ?string
    {
        return $this->extension;
    }

    /**
     * Get the language associated with the attachment.
     */
    public function language(): ?string
    {
        return $this->language;
    }

    /**
     * Get the display name for the attachment.
     */
    public function displayName(): ?string
    {
        return $this->displayName;
    }

    /**
     * Get the unmapped MAPI properties for the attachment.
     *
     * @return RawProperty[]
     */
    public function rawProperties(): array
    {
        return $this->rawProperties;
    }

    /**
     * Return the current editable-state revision without resolving the payload.
     *
     * @internal
     */
    public function revision(): int
    {
        return $this->revision;
    }

    private function resolveContent(): string
    {
        if ($this->content === null) {
            return '';
        }

        if ($this->content instanceof BinarySource) {
            return $this->content->contents();
        }

        $this->content = $this->resolve($this->content);

        return $this->content;
    }

    /**
     * Resolve the attachment payload into a repeatable binary source.
     */
    private function source(): BinarySource
    {
        if ($this->content instanceof BinarySource) {
            return $this->content;
        }

        $this->content = $this->resolveContent();

        return BinarySource::fromString($this->content);
    }

    private function resolve(string|Closure $data): string
    {
        $resolved = $data instanceof Closure ? $data() : $data;

        throw_unless(is_string($resolved), RuntimeException::class, 'Attachment data resolver must return a string.');

        return $resolved;
    }
}
