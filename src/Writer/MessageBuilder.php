<?php

declare(strict_types=1);

namespace MsgViewer\Writer;

use DateTimeImmutable;

final class MessageBuilder
{
    /** @var RecipientPayload[] */
    private array $recipients = [];

    /** @var AttachmentPayload[] */
    private array $attachments = [];

    public function __construct(
        public ?string $subject = null,
        public ?string $senderName = null,
        public ?string $senderEmail = null,
        public ?string $bodyPlain = null,
        public ?string $bodyHtml = null,
        public ?string $bodyRtf = null,
        public ?string $headers = null,
        public ?DateTimeImmutable $date = null
    ) {}

    public static function make(
        ?string $subject = null,
        ?string $senderName = null,
        ?string $senderEmail = null
    ): self {
        return new self($subject, $senderName, $senderEmail);
    }

    public function recipient(RecipientPayload|string $name, ?string $email = null): self
    {
        if (! $name instanceof RecipientPayload) {
            $name = new RecipientPayload($name, $email);
        }

        $this->recipients[] = $name;

        return $this;
    }

    public function attachment(AttachmentPayload|string $fileName, ?string $content = null): self
    {
        if (! $fileName instanceof AttachmentPayload) {
            $fileName = new AttachmentPayload(
                fileName: $fileName,
                displayName: $fileName,
                content: $content ?? ''
            );
        }

        $this->attachments[] = $fileName;

        return $this;
    }

    /**
     * @return RecipientPayload[]
     */
    public function recipients(): array
    {
        return $this->recipients;
    }

    /**
     * @return AttachmentPayload[]
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /** @deprecated Use recipient() */
    public function addRecipient(RecipientPayload $recipient): void
    {
        $this->recipient($recipient);
    }

    /** @deprecated Use attachment() */
    public function addAttachment(AttachmentPayload $attachment): void
    {
        $this->attachment($attachment);
    }
}
