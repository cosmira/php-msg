<?php

declare(strict_types=1);

namespace MsgViewer;

use MsgViewer\CompoundFile\CompoundFile;

final class Message
{
    /**
     * @param Attachment[] $attachments
     * @param Recipient[]  $recipients
     */
    public function __construct(
        public readonly CompoundFile $file,
        public readonly MessageContent $content,
        public readonly array $attachments,
        public readonly array $recipients
    ) {}

    public static function parse(string $binary): static
    {
        return MessageParser::parse($binary);
    }

    /**
     * Returns a recursive array representing the full nesting tree of the message.
     *
     * @return array{
     *     subject: string|null,
     *     senderName: string|null,
     *     senderEmail: string|null,
     *     date: string|null,
     *     recipients: list<array{name: string|null, email: string|null}>,
     *     attachments: list<array{fileName: string|null, displayName: string|null, mimeType: string|null, embedded: array<mixed>|null}>
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
                    'embedded'    => $a->embedded?->toArray(),
                ],
                $this->attachments
            ),
        ];
    }
}
