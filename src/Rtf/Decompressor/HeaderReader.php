<?php

declare(strict_types=1);

namespace MsgViewer\Rtf\Decompressor;

use MsgViewer\IO\BinaryBuffer;

final class HeaderReader
{
    public static function read(BinaryBuffer $buffer): Header
    {
        $offset = 0;

        $compSize = $buffer->getUint32($offset);
        $offset += 4;

        $rawSize = $buffer->getUint32($offset);
        $offset += 4;

        $compType = $buffer->getUint32($offset) === 0x75465A4C
            ? CompType::Compressed
            : CompType::Uncompressed;
        $offset += 4;

        $crc = $buffer->getUint32($offset);
        $offset += 4;

        return new Header($compSize, $rawSize, $compType, $crc, $offset);
    }
}
