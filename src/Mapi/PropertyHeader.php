<?php

declare(strict_types=1);

namespace MsgViewer\Mapi;

final readonly class PropertyHeader
{
    public function __construct(
        public int $size,
        public ?int $nextRecipientId = null,
        public ?int $nextAttachmentId = null,
        public ?int $recipientCount = null,
        public ?int $attachmentCount = null
    ) {}
}
