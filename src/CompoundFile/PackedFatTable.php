<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\CompoundFile;

use ArrayAccess;
use Countable;
use JsonSerializable;
use LogicException;

/**
 * Provide compact FAT access without expanding every entry into a PHP integer.
 *
 * @implements ArrayAccess<mixed, int>
 *
 * @internal
 */
final class PackedFatTable implements ArrayAccess, Countable, JsonSerializable
{
    private const ENTRY_SIZE = 4;

    /**
     * Create a table from packed little-endian unsigned 32-bit entries.
     */
    public function __construct(private string $entries)
    {
        throw_if(strlen($entries) % self::ENTRY_SIZE !== 0, LogicException::class, 'Packed FAT data must contain complete 32-bit entries.');
    }

    /**
     * Determine whether an entry exists at the given sector index.
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && $offset >= 0 && $offset < $this->count();
    }

    /**
     * Return the FAT value stored at the given sector index.
     */
    public function offsetGet(mixed $offset): ?int
    {
        if (! is_int($offset) || ! $this->offsetExists($offset)) {
            return null;
        }

        /** @var array{value: int} $value */
        $value = unpack('Vvalue', $this->entries, $offset * self::ENTRY_SIZE);

        return $value['value'];
    }

    /**
     * Replace an existing FAT value, primarily for controlled diagnostics.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw_unless(is_int($offset) && $this->offsetExists($offset), LogicException::class, 'Packed FAT entries require an existing integer offset and value.');
        $this->entries = substr_replace($this->entries, pack('V', $value), $offset * self::ENTRY_SIZE, self::ENTRY_SIZE);
    }

    /**
     * Reject attempts to remove entries from the read-only FAT table.
     */
    public function offsetUnset(mixed $offset): never
    {
        throw new LogicException('Packed FAT tables are immutable.');
    }

    /**
     * Return the number of entries retained by the table.
     */
    public function count(): int
    {
        return max(0, intdiv(strlen($this->entries), self::ENTRY_SIZE));
    }

    /**
     * Expand the table only when JSON diagnostics explicitly request it.
     *
     * @return list<int>
     */
    public function jsonSerialize(): array
    {
        if ($this->entries === '') {
            return [];
        }

        $entries = unpack('V*', $this->entries);
        throw_unless(is_array($entries), LogicException::class, 'Packed FAT entries could not be expanded.');

        $values = [];
        foreach ($entries as $entry) {
            throw_unless(is_int($entry), LogicException::class, 'Packed FAT entries must decode to integers.');
            $values[] = $entry;
        }

        return $values;
    }
}
