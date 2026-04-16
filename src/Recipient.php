<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

final readonly class Recipient
{
    /**
     * @param RawProperty[] $rawProperties MAPI properties not mapped to named fields
     */
    public function __construct(
        public ?string $name,
        public ?string $email,
        public ?int $type = null,
        public array $rawProperties = [],
    ) {}

    /**
     * Get the display name for the recipient.
     */
    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * Get the email address for the recipient.
     */
    public function email(): ?string
    {
        return $this->email;
    }

    /**
     * Get the recipient type, such as To, Cc, or Bcc.
     */
    public function type(): ?int
    {
        return $this->type;
    }

    /**
     * Get the raw MAPI properties that were not mapped to named fields.
     *
     * @return RawProperty[]
     */
    public function rawProperties(): array
    {
        return $this->rawProperties;
    }

    /**
     * Get the raw MAPI properties that were not mapped to named fields.
     *
     * @return RawProperty[]
     */
    public function getRawProperties(): array
    {
        return $this->rawProperties();
    }
}
