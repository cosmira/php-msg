<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage;

/**
 * Represents a raw MAPI property that is not mapped to a named field.
 * Preserving these allows full round-trip fidelity and access to
 * non-standard or extended MAPI properties.
 */
final readonly class RawProperty
{
    /**
     * Create an unmapped MAPI property value.
     *
     * @param string $id     4-char hex property ID, e.g. '0037'
     * @param int    $typeId MAPI property type ID, e.g. 0x001F (PtypString)
     * @param mixed  $value  Decoded value: string for string/binary, int|BigInteger for integers
     * @param int    $flags  Property flags from the property stream
     */
    public function __construct(
        /**
         * The four-character hexadecimal property identifier.
         */
        public string $id,
        /**
         * The numeric MAPI property-type identifier.
         */
        public int $typeId,
        /**
         * The decoded property value.
         */
        public mixed $value,
        /**
         * The flags stored alongside the property value.
         */
        public int $flags = 0,
    ) {}

    /**
     * Returns the 8-char property tag: ID (4) + type (4) in hex, e.g. '0037001f'.
     */
    public function tag(): string
    {
        return sprintf('%04s%04s', $this->id, str_pad(dechex($this->typeId), 4, '0', STR_PAD_LEFT));
    }
}
