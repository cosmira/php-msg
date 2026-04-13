<?php

declare(strict_types=1);

namespace MsgViewer\Writer;

use MsgViewer\RawProperty;

final class RecipientPayload
{
    /** Recipient type: 1 = To, 2 = Cc, 3 = Bcc (PR_RECIPIENT_TYPE, MS-OXCMSG §2.2.3.1) */
    public const TO = 1;

    public const CC = 2;

    public const BCC = 3;

    /**
     * @param RawProperty[] $rawProperties Extra MAPI properties to write verbatim (round-trip).
     */
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public int $type = self::TO,
        public array $rawProperties = [],
    ) {}
}
