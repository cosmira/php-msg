<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

use Closure;
use Cosmira\OutlookMessage\Exception\UnsupportedAttachmentMethodException;
use RuntimeException;

final class Attachment
{
    /**
     * @param RawProperty[] $rawProperties MAPI properties not mapped to named fields
     */
    public function __construct(
        private ?string $extension = null,
        private ?string $fileName = null,
        private ?string $mimeType = null,
        private readonly ?string $language = null,
        private ?string $displayName = null,
        private string|Closure|null $content = null,
        private ?Message $embedded = null,
        private ?string $contentId = null,
        private bool $inline = false,
        private readonly array $rawProperties = [],
        private readonly ?AttachmentMethod $method = null,
    ) {}

    public static function fromData(string|Closure $data, ?string $name = null): self
    {
        return (new self(content: $data, method: AttachmentMethod::ByValue))->as($name);
    }

    public static function fromPath(string $path): self
    {
        return self::fromData(static function () use ($path): string {
            $data = @file_get_contents($path);

            if ($data === false) {
                throw new RuntimeException(sprintf('Unable to read attachment from "%s".', $path));
            }

            return $data;
        }, basename($path));
    }

    public static function fromMessage(Message $message, ?string $name = 'message.msg'): self
    {
        return (new self(embedded: $message, method: AttachmentMethod::EmbeddedMessage))->as($name);
    }

    public function data(): string
    {
        return match ($this->method) {
            AttachmentMethod::ByValue         => $this->resolveContent(),
            AttachmentMethod::EmbeddedMessage => $this->embedded?->toBinary()
                ?? throw new RuntimeException('Embedded attachment has no message payload.'),
            default => throw UnsupportedAttachmentMethodException::for($this->method),
        };
    }

    public function withData(string|Closure $data): self
    {
        match ($this->method) {
            AttachmentMethod::ByValue         => $this->content = $data,
            AttachmentMethod::EmbeddedMessage => $this->embedded = Message::from($this->resolve($data)),
            default                           => throw UnsupportedAttachmentMethodException::for($this->method),
        };

        return $this;
    }

    public function message(): ?Message
    {
        return $this->embedded;
    }

    public function withMessage(Message $message): self
    {
        if ($this->method !== AttachmentMethod::EmbeddedMessage) {
            throw UnsupportedAttachmentMethodException::for($this->method);
        }

        $this->embedded = $message;

        return $this;
    }

    public function as(?string $name): self
    {
        if ($name === null) {
            return $this;
        }

        $this->fileName = $name;
        $this->displayName = $name;
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $this->extension = $extension === '' ? null : '.'.$extension;

        return $this;
    }

    public function withMime(?string $mime): self
    {
        $this->mimeType = $mime;

        return $this;
    }

    public function inline(?string $contentId = null): self
    {
        $this->inline = true;
        $this->contentId = $contentId;

        return $this;
    }

    public function name(): ?string
    {
        return $this->fileName ?? $this->displayName;
    }

    public function mime(): ?string
    {
        return $this->mimeType;
    }

    public function contentId(): ?string
    {
        return $this->contentId;
    }

    public function isInline(): bool
    {
        return $this->inline;
    }

    public function isEmbedded(): bool
    {
        return $this->method === AttachmentMethod::EmbeddedMessage;
    }

    public function method(): ?AttachmentMethod
    {
        return $this->method;
    }

    public function extension(): ?string
    {
        return $this->extension;
    }

    public function language(): ?string
    {
        return $this->language;
    }

    public function displayName(): ?string
    {
        return $this->displayName;
    }

    /** @return RawProperty[] */
    public function rawProperties(): array
    {
        return $this->rawProperties;
    }

    private function resolveContent(): string
    {
        if ($this->content === null) {
            return '';
        }

        $this->content = $this->resolve($this->content);

        return $this->content;
    }

    private function resolve(string|Closure $data): string
    {
        $resolved = $data instanceof Closure ? $data() : $data;

        throw_unless(is_string($resolved), RuntimeException::class, 'Attachment data resolver must return a string.');

        return $resolved;
    }
}
