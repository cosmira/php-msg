<?php

declare(strict_types=1);

namespace MsgViewer\Message;

use MsgViewer\CompoundFile\CompoundFile;

final class Message
{
    /**
     * @param Attachment[] $attachments
     * @param Recipient[]  $recipients
     */
    public function __construct(
        public readonly CompoundFile $file,
        public readonly MessageContent $content,
        public readonly array $attachments,
        public readonly array $recipients
    ) {}
}
