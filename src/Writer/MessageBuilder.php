<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Closure;
use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\MessageEditorFormat;
use Cosmira\OutlookMessage\MessageImportance;
use Cosmira\OutlookMessage\MessagePriority;
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
     * Whether source attachment storages were intentionally discarded.
     */
    private bool $sourceAttachmentsFlushed = false;

    /**
     * Whether absent optional metadata should be materialized with writer defaults.
     */
    private bool $writeMissingMetadataDefaults = true;

    /**
     * Whether a missing conversation topic should be derived from the subject.
     */
    private bool $deriveConversationTopic = true;

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
        /**
         * The message delivery time.
         */
        public ?DateTimeImmutable $receivedAt = null,
        /**
         * The represented sender display name.
         */
        public ?string $representingName = null,
        /**
         * The represented sender email address.
         */
        public ?string $representingEmail = null,
        /**
         * The numeric PidTagImportance value, or null for the normal default.
         */
        public ?int $importance = null,
        /**
         * The numeric PidTagPriority value, or null for the default.
         */
        public ?int $priority = null,
        /**
         * Whether the generated message is an unsent draft.
         */
        public bool $draft = true,
        /**
         * Whether a read receipt is requested.
         */
        public bool $readReceiptRequested = false,
        /**
         * The Outlook icon index hint.
         */
        public ?int $iconIndex = null,
        /**
         * The preferred message editor format.
         */
        public ?int $editorFormat = null,
        /**
         * The RFC message identifier.
         */
        public ?string $internetMessageId = null,
        /**
         * The RFC References field.
         */
        public ?string $internetReferences = null,
        /**
         * The parent message identifier for replies.
         */
        public ?string $inReplyToId = null,
        /**
         * The MAPI message class written to PidTagMessageClass.
         */
        public string $messageClass = 'IPM.Note',
        /**
         * An explicit conversation topic, or null to derive it from the subject.
         */
        public ?string $conversationTopic = null,
        /**
         * The raw server-generated message submission identifier.
         */
        public ?string $messageSubmissionId = null,
        /**
         * The source codepage used for preserved legacy PtypString8 properties.
         */
        public ?int $codepage = null,
        /**
         * The Windows locale identifier associated with the source message.
         */
        public ?int $messageLocaleId = null,
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
            senderName: $message->actualSenderName(),
            senderEmail: $message->actualSenderEmail(),
            body: $message->body(),
            bodyHtml: $message->bodyHtml(),
            bodyRtf: $message->bodyRtf(),
            headers: $message->headers(),
            date: $message->date(),
            bodyRtfCompressed: $message->content->bodyRtfCompressed,
            receivedAt: $message->receivedAt(),
            representingName: $message->representingName(),
            representingEmail: $message->representingEmail(),
            importance: $message->content->importance,
            priority: $message->content->priority,
            draft: $message->isDraft(),
            readReceiptRequested: $message->readReceiptRequested(),
            iconIndex: $message->iconIndex(),
            editorFormat: $message->content->editorFormat,
            internetMessageId: $message->internetMessageId(),
            internetReferences: $message->internetReferences(),
            inReplyToId: $message->inReplyToId(),
            messageClass: $message->messageClass() ?? 'IPM.Note',
            conversationTopic: $message->conversationTopic(),
            messageSubmissionId: $message->messageSubmissionId(),
            codepage: $message->content->codepage,
            messageLocaleId: $message->content->messageLocaleId,
        );

        $builder->writeMissingMetadataDefaults = false;
        $builder->deriveConversationTopic = $message->conversationTopic() !== null;

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
     * Set the identity represented by the physical sender.
     */
    public function representedBy(string $name, ?string $email = null): self
    {
        $this->representingName = $name;
        $this->representingEmail = $email;

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
     * Set the message delivery time.
     */
    public function receivedAt(DateTimeImmutable $date): self
    {
        $this->receivedAt = $date;

        return $this;
    }

    /**
     * Set the message importance.
     */
    public function importance(MessageImportance $importance): self
    {
        $this->importance = $importance->value;

        return $this;
    }

    /**
     * Set the message priority.
     */
    public function priority(MessagePriority $priority): self
    {
        $this->priority = $priority->value;

        return $this;
    }

    /**
     * Mark the generated message as a draft or sent item.
     */
    public function draft(bool $draft = true): self
    {
        $this->draft = $draft;

        return $this;
    }

    /**
     * Request or clear a read-receipt request.
     */
    public function requestReadReceipt(bool $requested = true): self
    {
        $this->readReceiptRequested = $requested;

        return $this;
    }

    /**
     * Set the Outlook icon index hint.
     */
    public function iconIndex(?int $iconIndex): self
    {
        $this->iconIndex = $iconIndex;

        return $this;
    }

    /**
     * Set the preferred editor format.
     */
    public function editorFormat(MessageEditorFormat $format): self
    {
        $this->editorFormat = $format->value;

        return $this;
    }

    /**
     * Set the RFC message identifier.
     */
    public function messageId(?string $messageId): self
    {
        $this->internetMessageId = $messageId;

        return $this;
    }

    /**
     * Set the RFC References field.
     */
    public function references(?string $references): self
    {
        $this->internetReferences = $references;

        return $this;
    }

    /**
     * Set the parent message identifier for a reply.
     */
    public function inReplyTo(?string $messageId): self
    {
        $this->inReplyToId = $messageId;

        return $this;
    }

    /**
     * Set the MAPI message class.
     */
    public function messageClass(string $messageClass): self
    {
        $this->messageClass = $messageClass;

        return $this;
    }

    /**
     * Set an explicit normalized conversation topic.
     */
    public function conversationTopic(?string $topic): self
    {
        $this->conversationTopic = $topic;
        $this->deriveConversationTopic = false;

        return $this;
    }

    /**
     * Determine whether the normalized conversation topic should be synthesized.
     *
     * @internal
     */
    public function shouldDeriveConversationTopic(): bool
    {
        return $this->deriveConversationTopic;
    }

    /**
     * Determine whether absent optional metadata should receive writer defaults.
     *
     * @internal
     */
    public function shouldWriteMissingMetadataDefaults(): bool
    {
        return $this->writeMissingMetadataDefaults;
    }

    /**
     * Set the raw server-generated message submission identifier.
     */
    public function submissionId(?string $submissionId): self
    {
        $this->messageSubmissionId = $submissionId;

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
     * Add an attachment directly from data.
     */
    public function attachData(string|Closure $data, ?string $name = null, ?string $mime = null): self
    {
        return $this->attach(Attachment::fromData($data, $name)->withMime($mime));
    }

    /**
     * Add a lazily loaded attachment from a path.
     */
    public function attachPath(string $path, ?string $mime = null): self
    {
        return $this->attach(Attachment::fromPath($path)->withMime($mime));
    }

    /**
     * Add an embedded Outlook message.
     */
    public function attachMessage(Message $message, ?string $name = 'message.msg'): self
    {
        return $this->attach(Attachment::fromMessage($message, $name));
    }

    /**
     * Add an inline by-value attachment.
     */
    public function inlineData(
        string|Closure $data,
        ?string $name = null,
        ?string $contentId = null,
        ?string $mime = null,
    ): self {
        return $this->attach(
            Attachment::fromData($data, $name)
                ->withMime($mime)
                ->inline($contentId),
        );
    }

    /**
     * Remove a specific attachment instance from the builder.
     */
    public function detach(Attachment $attachment): self
    {
        $this->attachments = array_values(array_filter(
            $this->attachments,
            static fn (Attachment $candidate): bool => $candidate !== $attachment,
        ));

        return $this;
    }

    /**
     * Remove every attachment from the builder.
     */
    public function withoutAttachments(): self
    {
        return $this->flushAttachments();
    }

    /**
     * Remove every attachment and prevent source attachment storages from being restored.
     */
    public function flushAttachments(): self
    {
        $this->attachments = [];
        $this->sourceAttachmentsFlushed = true;

        return $this;
    }

    /**
     * Remove every attachment using the singular fluent alias.
     */
    public function flushAttachment(): self
    {
        return $this->flushAttachments();
    }

    /**
     * Determine whether source attachment storages must be discarded.
     *
     * @internal
     */
    public function sourceAttachmentsFlushed(): bool
    {
        return $this->sourceAttachmentsFlushed;

    }

    /**
     * Remove every recipient from the builder.
     */
    public function withoutRecipients(): self
    {
        $this->recipients = [];

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
        $directory = dirname($path);
        $temporary = @tempnam($directory, '.outlook-msg-');
        if ($temporary === false) {
            throw new RuntimeException(sprintf('Unable to create a temporary message beside "%s".', $path));
        }

        $destination = @fopen($temporary, 'wb');

        try {
            throw_if($destination === false, RuntimeException::class, sprintf('Unable to open message destination "%s".', $temporary));
            MessageWriter::writeTo($this, $destination);
            fclose($destination);
            $destination = null;
            throw_unless(@rename($temporary, $path), RuntimeException::class, sprintf('Unable to write message to "%s".', $path));
        } finally {
            if (is_resource($destination)) {
                fclose($destination);
            }

            if (is_file($temporary)) {
                @unlink($temporary);
            }
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
