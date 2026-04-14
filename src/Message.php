<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

use Cosmira\OutlookMessage\Writer\MessageBuilder;

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

    public static function parse(string $binary): static
    {
        return MessageParser::parse($binary);
    }

    public static function from(string $binary): static
    {
        return self::parse($binary);
    }

    public static function make(
        ?string $subject = null,
        ?string $senderName = null,
        ?string $senderEmail = null
    ): MessageBuilder {
        return MessageBuilder::make($subject, $senderName, $senderEmail);
    }

    public function date(): ?\DateTimeImmutable
    {
        return $this->content->date;
    }

    public function subject(): ?string
    {
        return $this->content->subject;
    }

    public function senderName(): ?string
    {
        return $this->content->senderName;
    }

    public function senderEmail(): ?string
    {
        return $this->content->senderEmail;
    }

    public function body(): ?string
    {
        return $this->content->body;
    }

    public function bodyHtml(): ?string
    {
        return $this->content->bodyHtml;
    }

    public function bodyRtf(): ?string
    {
        return $this->content->bodyRtf;
    }

    public function headers(): ?string
    {
        return $this->content->headers;
    }

    public function to(): ?string
    {
        return $this->content->to;
    }

    public function cc(): ?string
    {
        return $this->content->cc;
    }

    public function bcc(): ?string
    {
        return $this->content->bcc;
    }

    /** @return RawProperty[] */
    public function rawProperties(): array
    {
        return $this->rawProperties;
    }

    /** @return RawProperty[] */
    public function getRawProperties(): array
    {
        return $this->rawProperties();
    }

    /**
     * Returns the best available body: HTML if set, else RTF text if set, else plain text.
     */
    public function preferredBody(): ?string
    {
        return $this->content->bodyHtml ?? $this->content->bodyRtf ?? $this->content->body;
    }

    /**
     * Returns the best available body: HTML if set, else RTF text if set, else plain text.
     */
    public function getPreferredBody(): ?string
    {
        return $this->preferredBody();
    }

    /**
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
            'subject'     => $this->content->subject,
            'senderName'  => $this->content->senderName,
            'senderEmail' => $this->content->senderEmail,
            'date'        => $this->content->date?->format('Y-m-d H:i:s'),
            'recipients'  => array_map(
                static fn (Recipient $r) => ['name' => $r->name, 'email' => $r->email],
                $this->recipients
            ),
            'attachments' => array_map(
                static fn (Attachment $a) => [
                    'fileName'    => $a->fileName,
                    'displayName' => $a->displayName,
                    'mimeType'    => $a->mimeType,
                    'contentId'   => $a->contentId,
                    'isInline'    => $a->isInline,
                    'embedded'    => $a->embedded?->toArray(),
                ],
                $this->attachments
            ),
        ];
    }
}
