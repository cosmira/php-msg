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
        /**
         * The delivery time, when distinct from the submission time.
         */
        public ?DateTimeImmutable $receivedAt = null,
        /**
         * The physical sender display name before represented-sender fallback.
         */
        public ?string $actualSenderName = null,
        /**
         * The physical sender email before represented-sender fallback.
         */
        public ?string $actualSenderEmail = null,
        /**
         * The display name of the messaging user represented by the sender.
         */
        public ?string $representingName = null,
        /**
         * The email address of the messaging user represented by the sender.
         */
        public ?string $representingEmail = null,
        /**
         * The numeric PidTagImportance value.
         */
        public ?int $importance = null,
        /**
         * The numeric PidTagPriority value.
         */
        public ?int $priority = null,
        /**
         * Whether the message is marked as unsent.
         */
        public bool $draft = false,
        /**
         * Whether a read receipt was requested.
         */
        public bool $readReceiptRequested = false,
        /**
         * The Outlook icon index hint.
         */
        public ?int $iconIndex = null,
        /**
         * The preferred editor format value.
         */
        public ?int $editorFormat = null,
        /**
         * The RFC message identifier stored in PidTagInternetMessageId.
         */
        public ?string $internetMessageId = null,
        /**
         * The RFC References field stored in PidTagInternetReferences.
         */
        public ?string $internetReferences = null,
        /**
         * The parent message identifier stored in PidTagInReplyToId.
         */
        public ?string $inReplyToId = null,
        /**
         * The MAPI message class, such as IPM.Note or IPM.Appointment.
         */
        public ?string $messageClass = null,
        /**
         * The normalized conversation topic stored by Outlook.
         */
        public ?string $conversationTopic = null,
        /**
         * The raw server-generated PidTagMessageSubmissionId payload.
         */
        public ?string $messageSubmissionId = null,
        /**
         * The effective codepage used to decode legacy PtypString8 properties.
         */
        public ?int $codepage = null,
        /**
         * The Windows locale identifier associated with the message.
         */
        public ?int $messageLocaleId = null,
    ) {}
}
