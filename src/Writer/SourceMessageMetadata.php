<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Cosmira\OutlookMessage\Support\BinarySource;

/** @internal */
final readonly class SourceMessageMetadata
{
    /**
     * @param list<string> $attachments
     * @param list<string> $recipients
     */
    public function __construct(
        public string|BinarySource $binary,
        public string $fingerprint,
        public array $attachments,
        public array $recipients,
    ) {}
}
