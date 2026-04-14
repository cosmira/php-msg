<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use DateTimeImmutable;
use Cosmira\OutlookMessage\RawProperty;

final class MessageBuilder
{
    /** @var RecipientPayload[] */
    private array $recipients = [];

    /** @var AttachmentPayload[] */
    private array $attachments = [];

    /** @var RawProperty[] */
    private array $rawProperties = [];

    public function __construct(
        public ?string $subject = null,
        public ?string $senderName = null,
        public ?string $senderEmail = null,
        public ?string $body = null,
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

    public function from(string $name, ?string $email = null): self
    {
        $this->senderName = $name;
        $this->senderEmail = $email;

        return $this;
    }

    public function subject(?string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function text(?string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function html(?string $body): self
    {
        $this->bodyHtml = $body;

        return $this;
    }

    public function rtf(?string $body): self
    {
        $this->bodyRtf = $body;

        return $this;
    }

    public function withHeaders(?string $headers): self
    {
        $this->headers = $headers;

        return $this;
    }

    public function sentAt(DateTimeImmutable $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function to(RecipientPayload|string $name, ?string $email = null): self
    {
        return $this->addRecipientOfType(RecipientPayload::TO, $name, $email);
    }

    public function cc(RecipientPayload|string $name, ?string $email = null): self
    {
        return $this->addRecipientOfType(RecipientPayload::CC, $name, $email);
    }

    public function bcc(RecipientPayload|string $name, ?string $email = null): self
    {
        return $this->addRecipientOfType(RecipientPayload::BCC, $name, $email);
    }

    public function recipient(RecipientPayload|string $name, ?string $email = null): self
    {
        return $this->addRecipientOfType(null, $name, $email);
    }

    public function attach(AttachmentPayload|string $fileName, ?string $content = null): self
    {
        return $this->attachment($fileName, $content);
    }

    public function attachment(AttachmentPayload|string $fileName, ?string $content = null): self
    {
        if (! $fileName instanceof AttachmentPayload) {
            $fileName = AttachmentPayload::file($fileName, $content ?? '');
        }

        $this->attachments[] = $fileName;

        return $this;
    }

    public function attachInline(
        string $fileName,
        string $content,
        ?string $contentId = null,
        ?string $displayName = null,
    ): self {
        $this->attachments[] = AttachmentPayload::inline($fileName, $content, $contentId, $displayName);

        return $this;
    }

    public function rawProperty(RawProperty $prop): self
    {
        $this->rawProperties[] = $prop;

        return $this;
    }

    public function withRawProperty(RawProperty $property): self
    {
        return $this->rawProperty($property);
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

    /**
     * @return RawProperty[]
     */
    public function getRawProperties(): array
    {
        return $this->rawProperties;
    }

    /**
     * @return RawProperty[]
     */
    public function rawProperties(): array
    {
        return $this->rawProperties;
    }

    /**
     * Adds an embedded .msg attachment (object type).
     */
    public function embeddedMsg(MessageBuilder $builder, string $displayName = 'message.msg'): self
    {
        $this->attachments[] = AttachmentPayload::embedded($builder, $displayName);

        return $this;
    }

    public function attachEmbedded(MessageBuilder $builder, string $displayName = 'message.msg'): self
    {
        $this->attachments[] = AttachmentPayload::embedded($builder, $displayName);

        return $this;
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

    private function addRecipientOfType(?int $type, RecipientPayload|string $name, ?string $email = null): self
    {
        $this->recipients[] = $this->newRecipient($name, $email, $type);

        return $this;
    }

    private function newRecipient(RecipientPayload|string $name, ?string $email = null, ?int $type = null): RecipientPayload
    {
        if (! $name instanceof RecipientPayload) {
            return new RecipientPayload($name, $email, $type ?? RecipientPayload::TO);
        }

        if ($type === null) {
            return $name;
        }

        return new RecipientPayload(
            $name->name,
            $name->email,
            $type,
            $name->rawProperties,
        );
    }
}
