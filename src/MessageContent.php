<?php

declare(strict_types=1);

namespace MsgViewer;

use DateTimeImmutable;

final class MessageContent
{
    public function __construct(
        public readonly ?DateTimeImmutable $date,
        public readonly ?string $subject,
        public readonly ?string $senderName,
        public readonly ?string $senderEmail,
        public readonly ?string $body,
        public readonly ?string $bodyHtml,
        public readonly ?string $bodyRtf,
        public readonly ?string $headers,
        public readonly ?string $to,
        public readonly ?string $cc
    ) {}
}
