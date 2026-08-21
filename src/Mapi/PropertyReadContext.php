<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;

final readonly class PropertyReadContext
{
    public function __construct(
        public CompoundFile $file,
        public DirectoryEntry $directory,
        public PropertyStreamEntry $properties,
        public ?int $codepage = null,
        public ?int $bodyCodepage = null,
        public ?int $htmlCodepage = null,
    ) {}

    public function codepageFor(PropertyDefinition $property): ?int
    {
        return match ($property->name) {
            'body'     => $this->bodyCodepage ?? $this->codepage,
            'bodyHtml' => $this->htmlCodepage ?? $this->codepage,
            default    => $this->codepage,
        };
    }
}
