<?php

declare(strict_types=1);

namespace MsgViewer\Message;

use DateTimeImmutable;

final class MessageContent
{
    public function __construct(
        public readonly ?DateTimeImmutable $date,
        public readonly ?string $subject,
        public readonly ?string $senderName,
        public readonly ?string $senderEmail,
        public readonly ?string $body,
        public readonly ?string $bodyHTML,
        public readonly ?string $bodyRTF,
        public readonly ?string $headers,
        public readonly ?string $toRecipients,
        public readonly ?string $ccRecipients
    ) {}
}
