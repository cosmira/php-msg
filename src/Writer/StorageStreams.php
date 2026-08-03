<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

final readonly class StorageStreams
{
    /**
     * Create a set of property and value streams for a storage.
     *
     * @param array<string, string> $streams
     */
    public function __construct(
        /**
         * The binary contents of the MAPI property stream.
         */
        public string $properties,
        /**
         * The named value streams keyed by compound-file stream name.
         */
        public array $streams = [],
    ) {}

    /**
     * Return a copy with the given property bytes appended.
     */
    public function appendProperties(string $binary): self
    {
        return new self($this->properties.$binary, $this->streams);
    }

    /**
     * Get the combined size of all property and value streams.
     */
    public function totalSize(): int
    {
        return strlen($this->properties) + array_sum(array_map(strlen(...), $this->streams));
    }

    /**
     * Write these streams into the given compound storage.
     */
    public function writeTo(CompoundBuilder $compound, int $storageIndex): void
    {
        $compound->addStream('__properties_version1.0', $this->properties, $storageIndex);

        foreach ($this->streams as $name => $data) {
            $compound->addStream($name, $data, $storageIndex);
        }
    }
}
