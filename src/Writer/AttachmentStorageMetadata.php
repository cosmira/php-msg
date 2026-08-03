<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Cosmira\OutlookMessage\Attachment;
use WeakMap;

/**
 * Writer-only MAPI metadata carried through a parsed message round trip.
 *
 * @internal
 */
final class AttachmentStorageMetadata
{
    /**
     * The rendering positions associated with live attachment instances.
     *
     * @var WeakMap<Attachment, int|null>|null
     */
    private static ?WeakMap $renderingPositions = null;

    /**
     * Remember the original rendering position for an attachment.
     */
    public static function rememberRenderingPosition(Attachment $attachment, ?int $position): void
    {
        self::$renderingPositions ??= new WeakMap();
        self::$renderingPositions[$attachment] = $position;
    }

    /**
     * Get the preserved rendering position for an attachment.
     */
    public static function renderingPosition(Attachment $attachment): ?int
    {
        return self::$renderingPositions[$attachment] ?? null;
    }
}
