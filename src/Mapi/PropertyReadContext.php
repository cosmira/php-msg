<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Mapi;

use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\CompoundFile\Directory\DirectoryEntry;

final readonly class PropertyReadContext
{
    /**
     * Bind the storage and its encoding metadata for property decoding.
     */
    public function __construct(
        /**
         * The compound file containing the property storage.
         */
        public CompoundFile $file,
        /**
         * The directory entry identifying the property storage.
         */
        public DirectoryEntry $directory,
        /**
         * The decoded fixed property records for this storage.
         */
        public PropertyStreamEntry $properties,
        /**
         * The default Windows codepage for encoded text.
         */
        public ?int $codepage = null,
        /**
         * The codepage selected for the plain-text body.
         */
        public ?int $bodyCodepage = null,
        /**
         * The codepage selected for the HTML body.
         */
        public ?int $htmlCodepage = null,
    ) {}

    /**
     * Select the property-specific encoding, falling back to the storage codepage.
     */
    public function codepageFor(PropertyDefinition $property): ?int
    {
        return match ($property->name) {
            'body'     => $this->bodyCodepage ?? $this->codepage,
            'bodyHtml' => $this->htmlCodepage ?? $this->codepage,
            default    => $this->codepage,
        };
    }
}
