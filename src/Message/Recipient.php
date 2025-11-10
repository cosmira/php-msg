<?php

declare(strict_types=1);

namespace MsgViewer\Message;

final class Recipient
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $email
    ) {
    }
}

