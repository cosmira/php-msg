<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Rtf;

use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Rtf\Decompressor\CompType;
use Cosmira\OutlookMessage\Rtf\Decompressor\Crc;
use Cosmira\OutlookMessage\Rtf\Decompressor\Decoder;
use Cosmira\OutlookMessage\Rtf\Decompressor\HeaderReader;
use Cosmira\OutlookMessage\Support\BinaryBuffer;

final class RtfDecompressor
{
    private const MAX_RAW_SIZE = 100 * 1024 * 1024;

    /**
     * Decompress an Outlook compressed-RTF payload.
     */
    public static function decompress(string $binary): string
    {
        $buffer = new BinaryBuffer($binary);
        $header = HeaderReader::read($buffer);

        throw_if(
            $header->rawSize > self::MAX_RAW_SIZE,
            CorruptedFileException::class,
            sprintf('RTF decompressed size exceeds maximum allowed (%d bytes).', self::MAX_RAW_SIZE),
        );

        if ($header->compType === CompType::Uncompressed) {
            return substr($binary, $header->headerSize, $header->rawSize);
        }

        $currentCrc = Crc::compute($binary, $header->headerSize);
        if ($currentCrc !== $header->crc) {
            throw new CorruptedFileException(sprintf('CRC mismatch. Expected %u, got %u.', $header->crc, $currentCrc));
        }

        return (new Decoder($binary, $header->rawSize, $header->headerSize))->decode();
    }
}
