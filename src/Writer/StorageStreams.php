<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

final readonly class StorageStreams
{
    /**
     * @param array<string, string> $streams
     */
    public function __construct(
        public string $properties,
        public array $streams = [],
    ) {}

    public function appendProperties(string $binary): self
    {
        return new self($this->properties.$binary, $this->streams);
    }

    public function writeTo(CompoundBuilder $compound, int $storageIndex): void
    {
        $compound->addStream('__properties_version1.0', $this->properties, $storageIndex);

        foreach ($this->streams as $name => $data) {
            $compound->addStream($name, $data, $storageIndex);
        }
    }
}
