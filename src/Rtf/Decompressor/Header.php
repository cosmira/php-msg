<?php

declare(strict_types=1);

namespace MsgViewer\Rtf\Decompressor;

final class Header
{
    public function __construct(
        public readonly int $compSize,
        public readonly int $rawSize,
        public readonly CompType $compType,
        public readonly int $crc,
        public readonly int $headerSize
    ) {
    }
}

