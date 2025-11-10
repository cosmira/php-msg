<?php

declare(strict_types=1);

namespace MsgViewer\Tests\Rtf;

use MsgViewer\Rtf\RtfDecompressor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use MsgViewer\Rtf\Decompressor\Crc;

final class RtfDecompressorTest extends TestCase
{
    public function testUncompressedPayload(): void
    {
        $payload = "{\\rtf1\\ansi some text}";
        $rawSize = strlen($payload);
        $compSize = $rawSize + 12;
        $header = pack('V', $compSize)
            . pack('V', $rawSize)
            . pack('V', 0x00000000)
            . pack('V', 0x00000000);

        $data = $header . $payload;
        $result = RtfDecompressor::decompress($data);

        self::assertSame($payload, $result);
    }

    public function testCrcMismatchThrows(): void
    {
        $header = pack('V', 16)
            . pack('V', 8)
            . pack('V', 0x75465A4C)
            . pack('V', 123456789);

        $data = $header . "\x00\x01\x02\x03\x04\x05\x06\x07";

        $this->expectException(RuntimeException::class);
        RtfDecompressor::decompress($data);
    }

    public function testCompressedPayload(): void
    {
        $raw = 'Hello';
        $compData = "\x00" . $raw; // control byte + literals
        $compSize = strlen($compData) + 12;
        $rawSize = strlen($raw);

        $header = pack('V', $compSize)
            . pack('V', $rawSize)
            . pack('V', 0x75465A4C)
            . pack('V', 0);

        $binary = $header . $compData;
        $crc = Crc::compute($binary, 16);
        $header = substr($header, 0, 12) . pack('V', $crc);
        $binary = $header . $compData;

        $result = RtfDecompressor::decompress($binary);

        self::assertSame($raw, $result);
    }
}

