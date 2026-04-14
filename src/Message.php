<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

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
     * Returns the best available body: HTML if set, else decompressed RTF if set, else plain text.
     */
    public function preferredBody(): ?string
    {
        return $this->content->bodyHtml ?? $this->content->bodyRtf ?? $this->content->body;
    }

    /**
     * Returns the best available body: HTML if set, else decompressed RTF if set, else plain text.
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
