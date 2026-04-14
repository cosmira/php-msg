<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Rtf\Decompressor;

final readonly class Header
{
    public function __construct(
        public int $compSize,
        public int $rawSize,
        public CompType $compType,
        public int $crc,
        public int $headerSize
    ) {}
}
