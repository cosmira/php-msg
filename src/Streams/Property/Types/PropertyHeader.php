<?php

declare(strict_types=1);

namespace MsgViewer\Streams\Property\Types;

final class PropertyHeader
{
    public function __construct(
        public readonly int $size,
        public readonly ?int $nextRecipientId = null,
        public readonly ?int $nextAttachmentId = null,
        public readonly ?int $recipientCount = null,
        public readonly ?int $attachmentCount = null
    ) {}
}
