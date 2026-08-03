<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

final readonly class PropertyHeader
{
    /**
     * Create a MAPI property-stream header.
     */
    public function __construct(
        /**
         * The property-stream header size in bytes.
         */
        public int $size,
        /**
         * The next available recipient row identifier.
         */
        public ?int $nextRecipientId = null,
        /**
         * The next available attachment row identifier.
         */
        public ?int $nextAttachmentId = null,
        /**
         * The number of recipient storages.
         */
        public ?int $recipientCount = null,
        /**
         * The number of attachment storages.
         */
        public ?int $attachmentCount = null
    ) {}
}
