<?php

declare(strict_types=1);

namespace MsgViewer\Writer;

use DateTimeImmutable;

final class MessageDraft
{
    /** @var RecipientDraft[] */
    public array $recipients = [];

    /** @var AttachmentDraft[] */
    public array $attachments = [];

    public function __construct(
        public ?string $subject = null,
        public ?string $senderName = null,
        public ?string $senderEmail = null,
        public ?string $bodyPlain = null,
        public ?string $bodyHtml = null,
        public ?string $bodyRtf = null,
        public ?string $headers = null,
        public ?DateTimeImmutable $date = null
    ) {
    }

    public function addRecipient(RecipientDraft $recipient): void
    {
        $this->recipients[] = $recipient;
    }

    public function addAttachment(AttachmentDraft $attachment): void
    {
        $this->attachments[] = $attachment;
    }
}

