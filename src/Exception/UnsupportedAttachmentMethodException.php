<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Exception;

use Cosmira\OutlookMessage\AttachmentMethod;
use RuntimeException;

final class UnsupportedAttachmentMethodException extends RuntimeException
{
    /**
     * Create an exception for the given unsupported attachment method.
     */
    public static function for(?AttachmentMethod $method): self
    {
        return new self(sprintf(
            'Attachment method %s does not expose an editable binary payload.',
            $method instanceof AttachmentMethod ? sprintf('%s (%d)', $method->name, $method->value) : 'unknown',
        ));
    }
}
