<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

use DateTimeImmutable;

final readonly class MessageContent
{
    /**
     * Create the decoded content for a message.
     */
    public function __construct(
        /**
         * The message submission date.
         */
        public ?DateTimeImmutable $date,
        /**
         * The message subject.
         */
        public ?string $subject,
        /**
         * The sender display name.
         */
        public ?string $senderName,
        /**
         * The sender email address.
         */
        public ?string $senderEmail,
        /**
         * The plain-text message body.
         */
        public ?string $body,
        /**
         * The HTML message body.
         */
        public ?string $bodyHtml,
        /**
         * The decompressed RTF message body.
         */
        public ?string $bodyRtf,
        /**
         * The raw transport headers.
         */
        public ?string $headers,
        /**
         * The original compressed RTF payload.
         */
        public ?string $bodyRtfCompressed = null,
    ) {}
}
