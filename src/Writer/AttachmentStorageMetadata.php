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
     * The source-backed opaque attachments that may retain their original storage tree.
     *
     * @var WeakMap<Attachment, true>|null
     */
    private static ?WeakMap $opaqueAttachments = null;

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

    /**
     * Mark an opaque attachment as backed by a parsed source storage.
     */
    public static function rememberOpaqueAttachment(Attachment $attachment): void
    {
        self::$opaqueAttachments ??= new WeakMap();
        self::$opaqueAttachments[$attachment] = true;
    }

    /**
     * Determine whether an opaque attachment can be restored from its source message.
     */
    public static function isOpaqueAttachment(Attachment $attachment): bool
    {
        return self::$opaqueAttachments[$attachment] ?? false;
    }
}
