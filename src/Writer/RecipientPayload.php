<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Recipient;

final class RecipientPayload
{
    /** Recipient type alias for Recipient::TYPE_TO. */
    public const TO = Recipient::TYPE_TO;

    /** Recipient type alias for Recipient::TYPE_CC. */
    public const CC = Recipient::TYPE_CC;

    /** Recipient type alias for Recipient::TYPE_BCC. */
    public const BCC = Recipient::TYPE_BCC;

    public static function to(?string $name = null, ?string $email = null): self
    {
        return new self($name, $email, self::TO);
    }

    public static function cc(?string $name = null, ?string $email = null): self
    {
        return new self($name, $email, self::CC);
    }

    public static function bcc(?string $name = null, ?string $email = null): self
    {
        return new self($name, $email, self::BCC);
    }

    /**
     * @param RawProperty[] $rawProperties Extra MAPI properties to write verbatim (round-trip).
     */
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public int $type = self::TO,
        public array $rawProperties = [],
    ) {}

    public function display(): ?string
    {
        return $this->email ?? $this->name;
    }
}
