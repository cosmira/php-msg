<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Rtf\Decompressor;

final readonly class Header
{
    /**
     * Create a decoded compressed-RTF header.
     */
    public function __construct(
        /**
         * The compressed payload size plus its trailing header fields.
         */
        public int $compSize,
        /**
         * The expected decompressed RTF size.
         */
        public int $rawSize,
        /**
         * The compression mode declared by the RTF container.
         */
        public CompType $compType,
        /**
         * The CRC-32 value for the compressed payload.
         */
        public int $crc,
        /**
         * The number of bytes occupied by the container header.
         */
        public int $headerSize
    ) {}
}
