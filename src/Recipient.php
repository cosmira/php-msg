<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

final readonly class Recipient
{
    public const TYPE_TO = 1;

    public const TYPE_CC = 2;

    public const TYPE_BCC = 3;

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
     * Get the best display value for the recipient.
     */
    public function display(): ?string
    {
        return $this->email ?? $this->name;
    }

    /**
     * Determine if the recipient is in the To group.
     */
    public function isTo(): bool
    {
        return $this->type === self::TYPE_TO;
    }

    /**
     * Determine if the recipient is in the Cc group.
     */
    public function isCc(): bool
    {
        return $this->type === self::TYPE_CC;
    }

    /**
     * Determine if the recipient is in the Bcc group.
     */
    public function isBcc(): bool
    {
        return $this->type === self::TYPE_BCC;
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
     *
     * @deprecated Use rawProperties()
     */
    public function getRawProperties(): array
    {
        return $this->rawProperties();
    }
}
