<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Cosmira\OutlookMessage\Support\BinarySource;

/** @internal */
final readonly class SourceMessageMetadata
{
    /**
     * Retain the source payload and fingerprints needed for round-trip matching.
     *
     * @param list<string> $attachments
     * @param list<string> $recipients
     */
    public function __construct(
        /**
         * The source bytes retained for decoding or round-trip comparison.
         */
        public string|BinarySource $binary,
        /**
         * The source message digest used to detect unchanged round trips.
         */
        public string $fingerprint,
        /**
         * Attachment fingerprints or storage indexes retained for round-trip matching.
         */
        public array $attachments,
        /**
         * Recipient fingerprints or storage indexes retained for round-trip matching.
         */
        public array $recipients,
    ) {}
}
