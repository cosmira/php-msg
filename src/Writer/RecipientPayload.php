<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Recipient;

final class RecipientPayload
{
    /**
     * Recipient type alias for Recipient::TYPE_TO.
     */
    public const TO = Recipient::TYPE_TO;

    /**
     * Recipient type alias for Recipient::TYPE_CC.
     */
    public const CC = Recipient::TYPE_CC;

    /**
     * Recipient type alias for Recipient::TYPE_BCC.
     */
    public const BCC = Recipient::TYPE_BCC;

    /**
     * Create a primary recipient payload.
     */
    public static function to(?string $name = null, ?string $email = null): self
    {
        return new self($name, $email, self::TO);
    }

    /**
     * Create a carbon-copy recipient payload.
     */
    public static function cc(?string $name = null, ?string $email = null): self
    {
        return new self($name, $email, self::CC);
    }

    /**
     * Create a blind-carbon-copy recipient payload.
     */
    public static function bcc(?string $name = null, ?string $email = null): self
    {
        return new self($name, $email, self::BCC);
    }

    /**
     * Create a recipient payload for the message writer.
     *
     * @param RawProperty[] $rawProperties Extra MAPI properties to write verbatim (round-trip).
     */
    public function __construct(
        /**
         * The recipient display name.
         */
        public ?string $name = null,
        /**
         * The recipient email address.
         */
        public ?string $email = null,
        /**
         * The MAPI recipient type.
         */
        public int $type = self::TO,
        /**
         * The unmapped MAPI properties to preserve for the recipient.
         */
        public array $rawProperties = [],
    ) {}

    /**
     * Get the best available display value for the recipient.
     */
    public function display(): ?string
    {
        return $this->email ?? $this->name;
    }
}
