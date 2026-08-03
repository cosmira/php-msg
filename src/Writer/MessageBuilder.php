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
    /**
     * The recipient payloads assigned to the message.
     *
     * @var RecipientPayload[]
     */
    private array $recipients = [];

    /**
     * The attachments assigned to the message.
     *
     * @var Attachment[]
     */
    private array $attachments = [];

    /**
     * The unmapped MAPI properties assigned to the message.
     *
     * @var RawProperty[]
     */
    private array $rawProperties = [];

    /**
     * The preserved NameID streams keyed by compound stream name.
     *
     * @var array<string, string>
     */
    private array $nameIdStreams = [];

    /**
     * Create a message builder with the given initial fields.
     */
    public function __construct(
        /**
         * The message subject.
         */
        public ?string $subject = null,
        /**
         * The sender display name.
         */
        public ?string $senderName = null,
        /**
         * The sender email address.
         */
        public ?string $senderEmail = null,
        /**
         * The plain-text message body.
         */
        public ?string $body = null,
        /**
         * The HTML message body.
         */
        public ?string $bodyHtml = null,
        /**
         * The decompressed RTF message body.
         */
        public ?string $bodyRtf = null,
        /**
         * The raw transport headers.
         */
        public ?string $headers = null,
        /**
         * The message submission date.
         */
        public ?DateTimeImmutable $date = null,
        /**
         * The original compressed RTF payload.
         */
        public ?string $bodyRtfCompressed = null,
    ) {}

    /**
     * Create a message builder with common sender fields.
     */
    public static function make(
        ?string $subject = null,
        ?string $senderName = null,
        ?string $senderEmail = null
    ): self {
        return new self($subject, $senderName, $senderEmail);
    }

    /**
     * Create a builder that preserves the data from a parsed message.
     */
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
        MessageStorageMetadata::copyToBuilder($message, $builder);

        return $builder;
    }

    /**
     * Set the sender name and email address.
     */
    public function from(string $name, ?string $email = null): self
    {
        $this->senderName = $name;
        $this->senderEmail = $email;

        return $this;
    }

    /**
     * Set the message subject.
     */
    public function subject(?string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Set the plain-text message body.
     */
    public function text(?string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Set the HTML message body.
     */
    public function html(?string $body): self
    {
        $this->bodyHtml = $body;

        return $this;
    }

    /**
     * Set the RTF message body.
     */
    public function rtf(?string $body): self
    {
        $this->bodyRtf = $body;
        $this->bodyRtfCompressed = null;

        return $this;
    }

    /**
     * Set the transport headers for the message.
     */
    public function withHeaders(?string $headers): self
    {
        $this->headers = $headers;

        return $this;
    }

    /**
     * Set the message submission date.
     */
    public function sentAt(DateTimeImmutable $date): self
    {
        $this->date = $date;

        return $this;
    }

    /**
     * Add a primary recipient to the message.
     */
    public function to(RecipientPayload|string $name, ?string $email = null): self
    {
        return $this->addRecipientOfType(Recipient::TYPE_TO, $name, $email);
    }

    /**
     * Add a carbon-copy recipient to the message.
     */
    public function cc(RecipientPayload|string $name, ?string $email = null): self
    {
        return $this->addRecipientOfType(Recipient::TYPE_CC, $name, $email);
    }

    /**
     * Add a blind-carbon-copy recipient to the message.
     */
    public function bcc(RecipientPayload|string $name, ?string $email = null): self
    {
        return $this->addRecipientOfType(Recipient::TYPE_BCC, $name, $email);
    }

    /**
     * Add a recipient payload to the message.
     */
    public function recipient(RecipientPayload|string $name, ?string $email = null): self
    {
        return $this->addRecipientOfType(null, $name, $email);
    }

    /**
     * Add an attachment to the message.
     */
    public function attach(Attachment $attachment): self
    {
        $this->attachments[] = $attachment;

        return $this;
    }

    /**
     * Add a raw MAPI property to the message.
     */
    public function rawProperty(RawProperty $prop): self
    {
        $this->rawProperties[] = $prop;

        return $this;
    }

    /**
     * Add a raw MAPI property using the fluent alias.
     */
    public function withRawProperty(RawProperty $property): self
    {
        return $this->rawProperty($property);
    }

    /**
     * Get all recipients currently assigned to the message.
     *
     * @return RecipientPayload[]
     */
    public function recipients(): array
    {
        return $this->recipients;
    }

    /**
     * Get all attachments currently assigned to the message.
     *
     * @return Attachment[]
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /**
     * Get the raw MAPI properties using the legacy accessor.
     *
     * @return RawProperty[]
     *
     * @deprecated Use rawProperties()
     */
    public function getRawProperties(): array
    {
        return $this->rawProperties;
    }

    /**
     * Get all raw MAPI properties assigned to the message.
     *
     * @return RawProperty[]
     */
    public function rawProperties(): array
    {
        return $this->rawProperties;
    }

    /**
     * Get the preserved NameID mapping streams.
     *
     * @return array<string, string>
     */
    public function nameIdStreams(): array
    {
        return $this->nameIdStreams;
    }

    /**
     * Serialize the message builder to Outlook MSG binary.
     */
    public function toBinary(): string
    {
        return MessageWriter::make($this);
    }

    /**
     * Save the built message to the given file path.
     */
    public function save(string $path = 'message.msg'): self
    {
        if (file_put_contents($path, $this->toBinary()) === false) {
            throw new RuntimeException(sprintf('Unable to write message to "%s".', $path));
        }

        return $this;
    }

    /**
     * Add a recipient using the legacy writer API.
     *
     * @deprecated Use recipient().
     */
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
