<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Recipient;
use DateTimeImmutable;
use RuntimeException;

final class MessageBuilder
{
    /** @var RecipientPayload[] */
    private array $recipients = [];

    /** @var Attachment[] */
    private array $attachments = [];

    /** @var RawProperty[] */
    private array $rawProperties = [];

    /** @var array<string, string> */
    private array $nameIdStreams = [];

    public function __construct(
        public ?string $subject = null,
        public ?string $senderName = null,
        public ?string $senderEmail = null,
        public ?string $body = null,
        public ?string $bodyHtml = null,
        public ?string $bodyRtf = null,
        public ?string $headers = null,
        public ?DateTimeImmutable $date = null,
        public ?string $bodyRtfCompressed = null,
    ) {}

    public static function make(
        ?string $subject = null,
        ?string $senderName = null,
        ?string $senderEmail = null
    ): self {
        return new self($subject, $senderName, $senderEmail);
    }

    public static function fromMessage(Message $message): self
    {
        $builder = new self(
            subject: $message->subject(),
            senderName: $message->senderName(),
            senderEmail: $message->senderEmail(),
            body: $message->body(),
            bodyHtml: $message->bodyHtml(),
            bodyRtf: $message->bodyRtf(),
            headers: $message->headers(),
            date: $message->date(),
            bodyRtfCompressed: $message->content->bodyRtfCompressed,
        );

        foreach ($message->rawProperties as $property) {
            $builder->rawProperty($property);
        }

        foreach ($message->recipients as $recipient) {
            $builder->recipient(new RecipientPayload(
                $recipient->name,
                $recipient->email,
                $recipient->type ?? Recipient::TYPE_TO,
                $recipient->rawProperties,
            ));
        }

        foreach ($message->attachments as $attachment) {
            $builder->attach($attachment);
        }

        $builder->nameIdStreams = $message->nameIdStreams;

        return $builder;
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
        $this->bodyRtfCompressed = null;

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
        return $this->addRecipientOfType(Recipient::TYPE_TO, $name, $email);
    }

    public function cc(RecipientPayload|string $name, ?string $email = null): self
    {
        return $this->addRecipientOfType(Recipient::TYPE_CC, $name, $email);
    }

    public function bcc(RecipientPayload|string $name, ?string $email = null): self
    {
        return $this->addRecipientOfType(Recipient::TYPE_BCC, $name, $email);
    }

    public function recipient(RecipientPayload|string $name, ?string $email = null): self
    {
        return $this->addRecipientOfType(null, $name, $email);
    }

    public function attach(Attachment $attachment): self
    {
        $this->attachments[] = $attachment;

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
     * @return Attachment[]
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /**
     * @return RawProperty[]
     *
     * @deprecated Use rawProperties()
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
     * @return array<string, string>
     */
    public function nameIdStreams(): array
    {
        return $this->nameIdStreams;
    }

    public function toBinary(): string
    {
        return MessageWriter::make($this);
    }

    public function save(string $path = 'message.msg'): self
    {
        if (file_put_contents($path, $this->toBinary()) === false) {
            throw new RuntimeException(sprintf('Unable to write message to "%s".', $path));
        }

        return $this;
    }

    /** @deprecated Use recipient() */
    public function addRecipient(RecipientPayload $recipient): void
    {
        $this->recipient($recipient);
    }

    private function addRecipientOfType(?int $type, RecipientPayload|string $name, ?string $email = null): self
    {
        $this->recipients[] = $this->newRecipient($name, $email, $type);

        return $this;
    }

    private function newRecipient(RecipientPayload|string $name, ?string $email = null, ?int $type = null): RecipientPayload
    {
        if (! $name instanceof RecipientPayload) {
            return new RecipientPayload($name, $email, $type ?? Recipient::TYPE_TO);
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
