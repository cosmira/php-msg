<?php

declare(strict_types=1);

namespace MsgViewer\Rtf;

use MsgViewer\Rtf\Decompressor\CompType;
use MsgViewer\Rtf\Decompressor\Crc;
use MsgViewer\Rtf\Decompressor\Dictionary;
use MsgViewer\Rtf\Decompressor\HeaderReader;
use MsgViewer\Support\BinaryBuffer;
use RuntimeException;

final class RtfDecompressor
{
    public static function decompress(string $binary): string
    {
        $buffer = new BinaryBuffer($binary);
        $header = HeaderReader::read($buffer);

        if ($header->compType === CompType::Uncompressed) {
            return substr($binary, $header->headerSize, $header->rawSize);
        }

        $currentCrc = Crc::compute($binary, $header->headerSize);
        if ($currentCrc !== $header->crc) {
            throw new RuntimeException(sprintf('CRC mismatch. Expected %u, got %u.', $header->crc, $currentCrc));
        }

        $dictionary = Dictionary::seed();
        $offset = $header->headerSize;
        $length = strlen($binary);
        $writeOffset = 207;
        $readOffset = 0;
        $output = [];
        $limit = min($length - 1, $header->compSize + 4);

        $canRun = true;
        while ($offset <= $limit && $canRun) {
            $control = ord($binary[$offset] ?? "\0");
            $offset += 1;

            for ($i = 0; $i < 8; $i++) {
                $bit = ($control >> $i) & 1;

                if ($bit === 0) {
                    if ($offset >= $length) {
                        $canRun = false;
                        break;
                    }

                    $literal = ord($binary[$offset]);
                    $offset += 1;

                    $dictionary[$writeOffset] = $literal;
                    $writeOffset = ($writeOffset + 1) % count($dictionary);

                    $output[] = $literal;
                } else {
                    if ($offset + 1 > $length) {
                        $canRun = false;
                        break;
                    }

                    $refBytes = substr($binary, $offset, 2);
                    if (strlen($refBytes) < 2) {
                        $canRun = false;
                        break;
                    }

                    $ref = unpack('vvalue', $refBytes)['value'];
                    $refOffset = $ref >> 4;
                    $offset += 2;

                    if ($refOffset === $writeOffset) {
                        $canRun = false;
                        break;
                    }

                    $readOffset = $refOffset;
                    $refLength = 2 + ($ref & 0x0F);
                    for ($j = 0; $j < $refLength; $j++) {
                        $byte = $dictionary[$readOffset];
                        $readOffset = ($readOffset + 1) % count($dictionary);

                        $dictionary[$writeOffset] = $byte;
                        $writeOffset = ($writeOffset + 1) % count($dictionary);

                        $output[] = $byte;
                    }
                }
            }
        }

        return self::bytesToString($output, $header->rawSize);
    }

    /**
     * @param int[] $bytes
     */
    private static function bytesToString(array $bytes, int $rawSize): string
    {
        if ($rawSize > 0 && count($bytes) > $rawSize) {
            $bytes = array_slice($bytes, 0, $rawSize);
        }

        return implode('', array_map(static fn (int $byte): string => chr($byte & 0xFF), $bytes));
    }
}
