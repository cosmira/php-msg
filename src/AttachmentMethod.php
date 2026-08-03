<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

/**
 * Values of the PidTagAttachMethod (0x3705) MAPI property.
 */
enum AttachmentMethod: int
{
    case None = 0;
    case ByValue = 1;
    case ByReference = 2;
    case ByReferenceResolve = 3;
    case ByReferenceOnly = 4;
    case EmbeddedMessage = 5;
    case Storage = 6;
    case ByWebReference = 7;
}
