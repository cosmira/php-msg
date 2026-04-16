<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

use Cosmira\OutlookMessage\Writer\MessageBuilder;
use Illuminate\Support\Collection;

final readonly class Message
{
    /**
     * @param Attachment[]  $attachments
     * @param Recipient[]   $recipients
     * @param RawProperty[] $rawProperties All MAPI properties not mapped to named fields
     */
    public function __construct(
        public MessageContent $content,
        public array $attachments,
        public array $recipients,
        public array $rawProperties = [],
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
     * Get the sent date for the message.
     */
    public function date(): ?\DateTimeImmutable
    {
        return $this->content->date;
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
                    'fileName'    => $a->fileName(),
                    'displayName' => $a->displayName(),
                    'mimeType'    => $a->mimeType(),
                    'contentId'   => $a->contentId(),
                    'isInline'    => $a->isInline(),
                    'embedded'    => $a->embedded()?->toArray(),
                ],
                $this->attachments
            ),
        ];
    }
}
