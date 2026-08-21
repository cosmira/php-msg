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
            $payload = substr($binary, $header->headerSize);
            throw_unless(
                self::matchesMelaSize(strlen($payload), $header->rawSize)
                    && self::matchesMelaSize(strlen($payload), $header->compSize),
                CorruptedFileException::class,
                'Uncompressed RTF size does not match the declared output size.',
            );

            return $payload;
        }

        throw_unless(
            $header->compSize + 4 === strlen($binary),
            CorruptedFileException::class,
            'Compressed-RTF size does not match the declared container size.',
        );

        $currentCrc = Crc::compute($binary, $header->headerSize);
        if ($currentCrc !== $header->crc) {
            throw new CorruptedFileException(sprintf('CRC mismatch. Expected %u, got %u.', $header->crc, $currentCrc));
        }

        return (new Decoder($binary, $header->rawSize, $header->headerSize))->decode();
    }

    /**
     * Accept the two MELA size conventions emitted by Outlook-compatible writers.
     */
    private static function matchesMelaSize(int $payloadSize, int $declaredSize): bool
    {
        return $declaredSize === $payloadSize || $declaredSize === $payloadSize + 12;
    }
}
