<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

use Cosmira\OutlookMessage\Writer\MessageBuilder;
use Illuminate\Support\Collection;
use RuntimeException;

final readonly class Message
{
    /**
     * Create a decoded Outlook message.
     *
     * @param Attachment[]         $attachments
     * @param Recipient[]          $recipients
     * @param RawProperty[]        $rawProperties All MAPI properties not mapped to named fields
     * @param array<string,string> $nameIdStreams Raw NameID mapping streams used by named properties
     */
    public function __construct(
        /**
         * The decoded content fields for the message.
         */
        public MessageContent $content,
        /**
         * The attachments belonging to the message.
         */
        public array $attachments,
        /**
         * The recipients belonging to the message.
         */
        public array $recipients,
        /**
         * The unmapped MAPI properties preserved for the message.
         */
        public array $rawProperties = [],
        /**
         * The raw NameID mapping streams preserved for named properties.
         */
        public array $nameIdStreams = [],
    ) {}

    /**
     * Parse a MSG payload into a message instance.
     */
    public static function parse(string $binary): static
    {
        return MessageParser::parse($binary);
    }

    /**
     * Create a message instance from raw MSG binary.
     */
    public static function from(string $binary): static
    {
        return self::parse($binary);
    }

    /**
     * Load a message from the given file path.
     */
    public static function fromPath(string $path): static
    {
        $binary = @file_get_contents($path);

        if ($binary === false) {
            throw new RuntimeException(sprintf('Unable to read message from "%s".', $path));
        }

        return self::from($binary);
    }

    /**
     * Start building a new message with the fluent writer API.
     */
    public static function make(
        ?string $subject = null,
        ?string $senderName = null,
        ?string $senderEmail = null
    ): MessageBuilder {
        return MessageBuilder::make($subject, $senderName, $senderEmail);
    }

    /**
     * Creates a writer builder that preserves parsed canonical and raw MAPI properties.
     */
    public function toBuilder(): MessageBuilder
    {
        return MessageBuilder::fromMessage($this);
    }

    /**
     * Serialize the message to Outlook MSG binary.
     */
    public function toBinary(): string
    {
        return $this->toBuilder()->toBinary();
    }

    /**
     * Save the message to the given file path.
     */
    public function save(string $path): self
    {
        if (@file_put_contents($path, $this->toBinary()) === false) {
            throw new RuntimeException(sprintf('Unable to write message to "%s".', $path));
        }

        return $this;
    }

    /**
     * Get the send date for the message.
     */
    public function date(): ?\DateTimeImmutable
    {
        return $this->content->date;
    }

    /**
     * Get the delivery time when it is available separately from submission.
     */
    public function receivedAt(): ?\DateTimeImmutable
    {
        return $this->content->receivedAt;
    }

    /**
     * Get the subject line for the message.
     */
    public function subject(): ?string
    {
        return $this->content->subject;
    }

    /**
     * Get the sender display name for the message.
     */
    public function senderName(): ?string
    {
        return $this->content->senderName;
    }

    /**
     * Get the sender email address for the message.
     */
    public function senderEmail(): ?string
    {
        return $this->content->senderEmail;
    }

    /**
     * Get the physical sender name before represented-sender fallback.
     */
    public function actualSenderName(): ?string
    {
        return $this->content->actualSenderName;
    }

    /**
     * Get the physical sender email before represented-sender fallback.
     */
    public function actualSenderEmail(): ?string
    {
        return $this->content->actualSenderEmail;
    }

    /**
     * Get the represented sender name.
     */
    public function representingName(): ?string
    {
        return $this->content->representingName;
    }

    /**
     * Get the represented sender email.
     */
    public function representingEmail(): ?string
    {
        return $this->content->representingEmail;
    }

    /**
     * Get the plain-text body for the message.
     */
    public function body(): ?string
    {
        return $this->content->body;
    }

    /**
     * Get the HTML body for the message.
     */
    public function bodyHtml(): ?string
    {
        return $this->content->bodyHtml;
    }

    /**
     * Get the decompressed RTF body for the message.
     */
    public function bodyRtf(): ?string
    {
        return $this->content->bodyRtf;
    }

    /**
     * Get the transport headers for the message.
     */
    public function headers(): ?string
    {
        return $this->content->headers;
    }

    /**
     * Get the message importance when it contains a known value.
     */
    public function importance(): ?MessageImportance
    {
        return $this->content->importance !== null
            ? MessageImportance::tryFrom($this->content->importance)
            : null;
    }

    /**
     * Get the message priority when it contains a known value.
     */
    public function priority(): ?MessagePriority
    {
        return $this->content->priority !== null
            ? MessagePriority::tryFrom($this->content->priority)
            : null;
    }

    /**
     * Determine whether the message is marked as an unsent draft.
     */
    public function isDraft(): bool
    {
        return $this->content->draft;
    }

    /**
     * Determine whether the sender requested a read receipt.
     */
    public function readReceiptRequested(): bool
    {
        return $this->content->readReceiptRequested;
    }

    /**
     * Get the Outlook icon index hint.
     */
    public function iconIndex(): ?int
    {
        return $this->content->iconIndex;
    }

    /**
     * Get the preferred message editor format when known.
     */
    public function editorFormat(): ?MessageEditorFormat
    {
        return $this->content->editorFormat !== null
            ? MessageEditorFormat::tryFrom($this->content->editorFormat)
            : null;
    }

    /**
     * Get the RFC message identifier.
     */
    public function internetMessageId(): ?string
    {
        return $this->content->internetMessageId;
    }

    /**
     * Get the RFC References field.
     */
    public function internetReferences(): ?string
    {
        return $this->content->internetReferences;
    }

    /**
     * Get the parent message identifier used by replies.
     */
    public function inReplyToId(): ?string
    {
        return $this->content->inReplyToId;
    }

    /**
     * Get the MAPI message class, such as IPM.Note or IPM.Appointment.
     */
    public function messageClass(): ?string
    {
        return $this->content->messageClass;
    }

    /**
     * Get the normalized conversation topic.
     */
    public function conversationTopic(): ?string
    {
        return $this->content->conversationTopic;
    }

    /**
     * Get the raw server-generated message submission identifier.
     */
    public function messageSubmissionId(): ?string
    {
        return $this->content->messageSubmissionId;
    }

    /**
     * Get the To recipients as a fluent collection.
     *
     * @return Collection<int, Recipient>
     */
    public function to(): Collection
    {
        return $this->recipientsBy(
            static fn (Recipient $recipient): bool => $recipient->isTo()
        );
    }

    /**
     * Get the Cc recipients as a fluent collection.
     *
     * @return Collection<int, Recipient>
     */
    public function cc(): Collection
    {
        return $this->recipientsBy(
            static fn (Recipient $recipient): bool => $recipient->isCc()
        );
    }

    /**
     * Get the Bcc recipients as a fluent collection.
     *
     * @return Collection<int, Recipient>
     */
    public function bcc(): Collection
    {
        return $this->recipientsBy(
            static fn (Recipient $recipient): bool => $recipient->isBcc()
        );
    }

    /**
     * Get the formatted To line from the message headers.
     */
    public function displayTo(): string
    {
        return $this->displayRecipients($this->to());
    }

    /**
     * Get the formatted Cc line from the message headers.
     */
    public function displayCc(): string
    {
        return $this->displayRecipients($this->cc());
    }

    /**
     * Get the formatted Bcc line from the message headers.
     */
    public function displayBcc(): string
    {
        return $this->displayRecipients($this->bcc());
    }

    /**
     * Get the underlying content value object.
     */
    public function content(): MessageContent
    {
        return $this->content;
    }

    /**
     * Get the attachments as a fluent collection.
     *
     * @return Collection<int, Attachment>
     */
    public function attachments(): Collection
    {
        return new Collection($this->attachments);
    }

    /**
     * Get the recipients as a fluent collection.
     *
     * @return Collection<int, Recipient>
     */
    public function recipients(): Collection
    {
        return new Collection($this->recipients);
    }

    /**
     * @param callable(Recipient): bool $filter
     *
     * @return Collection<int, Recipient>
     */
    private function recipientsBy(callable $filter): Collection
    {
        return $this->recipients()->filter($filter)->values();
    }

    /**
     * @param Collection<int, Recipient> $recipients
     */
    private function displayRecipients(Collection $recipients): string
    {
        return $recipients
            ->map(static fn (Recipient $recipient): string => $recipient->display() ?? '')
            ->implode(';');
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
     *
     * @deprecated Use rawProperties()
     */
    public function getRawProperties(): array
    {
        return $this->rawProperties();
    }

    /**
     * Get the best available body for the message.
     */
    public function preferredBody(): ?string
    {
        return $this->content->bodyHtml ?? $this->content->bodyRtf ?? $this->content->body;
    }

    /**
     * Get the best available body for the message.
     *
     * @deprecated Use preferredBody()
     */
    public function getPreferredBody(): ?string
    {
        return $this->preferredBody();
    }

    /**
     * Convert the message into a simple array representation.
     *
     * @return array{
     *     subject: string|null,
     *     senderName: string|null,
     *     senderEmail: string|null,
     *     date: string|null,
     *     recipients: array<array{name: string|null, email: string|null}>,
     *     attachments: array<array{fileName: string|null, displayName: string|null, mimeType: string|null, contentId: string|null, isInline: bool, embedded: array<mixed>|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'subject'     => $this->subject(),
            'senderName'  => $this->senderName(),
            'senderEmail' => $this->senderEmail(),
            'date'        => $this->date()?->format('Y-m-d H:i:s'),
            'recipients'  => array_map(
                static fn (Recipient $r) => ['name' => $r->name(), 'email' => $r->email()],
                $this->recipients
            ),
            'attachments' => array_map(
                static fn (Attachment $a) => [
                    'fileName'    => $a->name(),
                    'displayName' => $a->displayName(),
                    'mimeType'    => $a->mime(),
                    'contentId'   => $a->contentId(),
                    'isInline'    => $a->isInline(),
                    'embedded'    => $a->message()?->toArray(),
                ],
                $this->attachments
            ),
        ];
    }
}
