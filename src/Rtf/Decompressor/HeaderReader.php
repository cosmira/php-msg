<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Rtf\Decompressor;

use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Support\BinaryBuffer;

final class HeaderReader
{
    /**
     * Read and validate a compressed-RTF header.
     */
    public static function read(BinaryBuffer $buffer): Header
    {
        $offset = 0;

        $compSize = $buffer->getUint32($offset);
        $offset += 4;

        $rawSize = $buffer->getUint32($offset);
        $offset += 4;

        $type = $buffer->getUint32($offset);
        $compType = match ($type) {
            0x75465A4C => CompType::Compressed,
            0x414C454D => CompType::Uncompressed,
            default    => throw new CorruptedFileException(sprintf('Unsupported compressed-RTF magic value: 0x%08X.', $type)),
        };
        $offset += 4;

        $crc = $buffer->getUint32($offset);
        $offset += 4;

        return new Header($compSize, $rawSize, $compType, $crc, $offset);
    }
}
