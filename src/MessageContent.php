<?php

declare(strict_types=1);

namespace MsgViewer;

use DateTimeImmutable;

final readonly class MessageContent
{
    public function __construct(
        public ?DateTimeImmutable $date,
        public ?string $subject,
        public ?string $senderName,
        public ?string $senderEmail,
        public ?string $body,
        public ?string $bodyHtml,
        public ?string $bodyRtf,
        public ?string $headers,
        public ?string $to,
        public ?string $cc,
        public ?string $bcc = null,
    ) {}
}
