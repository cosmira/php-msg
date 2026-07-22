<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Rtf;

use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Rtf\Decompressor\CompType;
use Cosmira\OutlookMessage\Rtf\Decompressor\Crc;
use Cosmira\OutlookMessage\Rtf\Decompressor\Dictionary;
use Cosmira\OutlookMessage\Rtf\Decompressor\HeaderReader;
use Cosmira\OutlookMessage\Support\BinaryBuffer;

final class RtfDecompressor
{
    private const MAX_RAW_SIZE = 100 * 1024 * 1024;

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

        $dictionary = Dictionary::seed();
        $dictionarySize = count($dictionary);
        $dictionaryWrite = Dictionary::seedLength();
        $dictionaryEnd = $dictionaryWrite;
        $offset = $header->headerSize;
        $length = strlen($binary);
        $output = str_repeat("\0", $header->rawSize);
        $outputSize = 0;

        $canRun = true;
        $iterations = 0;
        $maxIterations = 10_000_000;

        while ($offset < $length && $canRun && $outputSize < $header->rawSize) {
            throw_if(++$iterations > $maxIterations, CorruptedFileException::class, 'RTF decompression exceeded maximum iteration count.');

            $control = ord($binary[$offset] ?? "\0");
            $offset += 1;

            for ($i = 0; $i < 8; $i++) {
                if ($outputSize >= $header->rawSize) {
                    $canRun = false;
                    break;
                }

                $bit = ($control >> $i) & 1;

                if ($bit === 0) {
                    if ($offset >= $length) {
                        $canRun = false;
                        break;
                    }

                    $literal = ord($binary[$offset]);
                    $offset += 1;

                    $dictionary[$dictionaryWrite] = $literal;
                    $dictionaryWrite += 1;
                    if ($dictionaryWrite > $dictionaryEnd) {
                        $dictionaryEnd = min($dictionaryWrite, $dictionarySize);
                    }

                    $dictionaryWrite %= $dictionarySize;

                    $output[$outputSize++] = chr($literal);
                } else {
                    if ($offset + 1 >= $length) {
                        $canRun = false;
                        break;
                    }

                    $ref = (ord($binary[$offset]) << 8) | ord($binary[$offset + 1]);
                    $refOffset = ($ref & 0xFFF0) >> 4;
                    $offset += 2;

                    throw_if(
                        $refOffset > $dictionaryEnd,
                        CorruptedFileException::class,
                        'RTF decompression encountered an invalid dictionary reference.'
                    );

                    if ($refOffset === $dictionaryWrite) {
                        $canRun = false;
                        break;
                    }

                    $readOffset = $refOffset;
                    $refLength = 2 + ($ref & 0x0F);
                    for ($j = 0; $j < $refLength; $j++) {
                        if ($outputSize >= $header->rawSize) {
                            $canRun = false;
                            break;
                        }

                        $byte = $dictionary[$readOffset];
                        $readOffset = ($readOffset + 1) % $dictionarySize;

                        $dictionary[$dictionaryWrite] = $byte;
                        $dictionaryWrite += 1;
                        if ($dictionaryWrite > $dictionaryEnd) {
                            $dictionaryEnd = min($dictionaryWrite, $dictionarySize);
                        }

                        $dictionaryWrite %= $dictionarySize;

                        $output[$outputSize++] = chr($byte & 0xFF);
                    }
                }
            }
        }

        return $outputSize === $header->rawSize
            ? $output
            : substr($output, 0, $outputSize);
    }
}
