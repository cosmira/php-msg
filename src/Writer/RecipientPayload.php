<?php

declare(strict_types=1);

namespace MsgViewer\Writer;

final class RecipientPayload
{
    public function __construct(
        public ?string $name = null,
        public ?string $email = null
    ) {}
}
