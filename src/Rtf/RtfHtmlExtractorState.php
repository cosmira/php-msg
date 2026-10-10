<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Rtf;

/**
 * Group-scoped parser state used while recovering encapsulated HTML.
 *
 * @internal
 */
final class RtfHtmlExtractorState
{
    /**
     * Whether the group is inside an \htmlrtf block that only carries RTF formatting.
     */
    public bool $suppressed = false;

    /**
     * Whether the whole group belongs to a destination that is not part of the HTML.
     */
    public bool $skipped = false;

    /**
     * Whether the group was opened with the \* ignorable destination marker.
     */
    public bool $ignorable = false;

    /**
     * Whether the next control word is the first one of the group.
     */
    public bool $destinationPending = false;

    /**
     * Whether the group belongs to the font table.
     */
    public bool $fontTable = false;

    /**
     * The number of fallback characters that follow each \u control word.
     */
    public int $unicodeSkip = 1;

    /**
     * The selected font, or null for the document default font.
     */
    public ?int $font = null;
}
