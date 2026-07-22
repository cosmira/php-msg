<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Rtf;

use Cosmira\OutlookMessage\Rtf\Decompressor\Crc;
use Cosmira\OutlookMessage\Rtf\RtfCompressor;
use Cosmira\OutlookMessage\Rtf\RtfDecompressor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class RtfDecompressorTest extends TestCase
{
    public function testUncompressedPayload(): void
    {
        $payload = '{\\rtf1\\ansi some text}';
        $rawSize = strlen($payload);
        $compSize = $rawSize + 12;
        $header = pack('V', $compSize)
            .pack('V', $rawSize)
            .pack('V', 0x00000000)
            .pack('V', 0x00000000);

        $data = $header.$payload;
        $result = RtfDecompressor::decompress($data);

        $this->assertSame($payload, $result);
    }

    public function testCompressorWrapsValidUncompressedPayload(): void
    {
        $payload = '{\\rtf1\\ansi\\deff0 test}';

        $binary = RtfCompressor::wrapUncompressed($payload);

        $this->assertSame($payload, RtfDecompressor::decompress($binary));
        /** @var array{1:int} $magic */
        $magic = unpack('V', substr($binary, 8, 4));
        /** @var array{1:int} $compSize */
        $compSize = unpack('V', substr($binary, 0, 4));
        /** @var array{1:int} $rawSize */
        $rawSize = unpack('V', substr($binary, 4, 4));
        $this->assertSame(0x414C454D, $magic[1]);
        $this->assertSame(strlen($payload) + 12, $compSize[1]);
        $this->assertSame(strlen($payload), $rawSize[1]);
    }

    public function testLargeCompressedPayloadFitsDefaultCliMemoryLimit(): void
    {
        $raw = str_repeat('A', 2 * 1024 * 1024);
        $compressed = '';
        foreach (str_split($raw, 8) as $literals) {
            $compressed .= "\0".$literals;
        }

        $header = pack('V', strlen($compressed) + 12)
            .pack('V', strlen($raw))
            .pack('V', 0x75465A4C)
            .pack('V', 0);
        $binary = $header.$compressed;
        $binary = substr($binary, 0, 12).pack('V', Crc::compute($binary, 16)).$compressed;

        $path = tempnam(sys_get_temp_dir(), 'rtf-memory-');
        $this->assertNotFalse($path);
        file_put_contents($path, $binary);

        try {
            $script = sprintf(
                'require %s; echo strlen(%s::decompress(file_get_contents($argv[1])));',
                var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true),
                RtfDecompressor::class,
            );
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script, $path]);
            $process->mustRun();

            $this->assertSame((string) strlen($raw), $process->getOutput());
        } finally {
            unlink($path);
        }
    }

    public function testRejectsUnreasonableDeclaredRawSize(): void
    {
        $binary = pack('V', 12)
            .pack('V', (100 * 1024 * 1024) + 1)
            .pack('V', 0x414C454D)
            .pack('V', 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RTF decompressed size exceeds maximum allowed');

        RtfDecompressor::decompress($binary);
    }

    public function testCrcMismatchThrows(): void
    {
        $header = pack('V', 16)
            .pack('V', 8)
            .pack('V', 0x75465A4C)
            .pack('V', 123456789);

        $data = $header."\x00\x01\x02\x03\x04\x05\x06\x07";

        $this->expectException(RuntimeException::class);
        RtfDecompressor::decompress($data);
    }

    public function testCompressedPayload(): void
    {
        $raw = 'Hello';
        $compData = "\x00".$raw; // control byte + literals
        $compSize = strlen($compData) + 12;
        $rawSize = strlen($raw);

        $header = pack('V', $compSize)
            .pack('V', $rawSize)
            .pack('V', 0x75465A4C)
            .pack('V', 0);

        $binary = $header.$compData;
        $crc = Crc::compute($binary, 16);
        $header = substr($header, 0, 12).pack('V', $crc);
        $binary = $header.$compData;

        $result = RtfDecompressor::decompress($binary);

        $this->assertSame($raw, $result);
    }

    public function testBackReferenceDecompression(): void
    {
        // Control byte 0x01: bit 0 = 1 → back-reference, bits 1-7 = 0
        // Back-ref: pack('v', 0) → refOffset=0, refLength=2 → reads dict[0]='{' and dict[1]='\'
        $compData = "\x01".pack('v', 0x0000);
        $rawSize = 2;
        $compSize = strlen($compData) + 12;

        $header = pack('V', $compSize)
            .pack('V', $rawSize)
            .pack('V', 0x75465A4C)
            .pack('V', 0);

        $binary = $header.$compData;
        $crc = Crc::compute($binary, 16);
        $binary = substr($binary, 0, 12).pack('V', $crc).$compData;

        $result = RtfDecompressor::decompress($binary);

        // Dictionary seeded with RTF template; dict[0]='{', dict[1]='\'
        $this->assertSame('{\\', $result);
    }

    public function testRawSizeTruncation(): void
    {
        // Control byte 0x00 → 8 literal bytes; we supply 5 literals but rawSize=2
        $compData = "\x00ABCDE";
        $rawSize = 2; // output will be truncated to 2 bytes
        $compSize = strlen($compData) + 12;

        $header = pack('V', $compSize)
            .pack('V', $rawSize)
            .pack('V', 0x75465A4C)
            .pack('V', 0);

        $binary = $header.$compData;
        $crc = Crc::compute($binary, 16);
        $binary = substr($binary, 0, 12).pack('V', $crc).$compData;

        $result = RtfDecompressor::decompress($binary);

        $this->assertSame('AB', $result);
    }

    public function testBackRefBeyondEndOfData(): void
    {
        // Control byte 0x01 (bit 0 = back-reference) with only 1 byte remaining
        // → triggers line 70: canRun = false when offset+1 > length
        $compData = "\x01\x00"; // control + only 1 byte (need 2 for ref)
        $rawSize = 1;
        $compSize = strlen($compData) + 12;

        $header = pack('V', $compSize)
            .pack('V', $rawSize)
            .pack('V', 0x75465A4C)
            .pack('V', 0);

        $binary = $header.$compData;
        $crc = Crc::compute($binary, 16);
        $binary = substr($binary, 0, 12).pack('V', $crc).$compData;

        $result = RtfDecompressor::decompress($binary);
        $this->assertSame('', $result);
    }

    public function testBackRefControlByteOnlyTriggersOffsetBeyondLength(): void
    {
        // Control byte 0x01 with NO following bytes → offset+1 > length (lines 69-70 TRUE branch)
        $compData = "\x01"; // only the control byte, no ref bytes follow
        $rawSize = 1;
        $compSize = strlen($compData) + 12;

        $header = pack('V', $compSize)
            .pack('V', $rawSize)
            .pack('V', 0x75465A4C)
            .pack('V', 0);

        $binary = $header.$compData;
        $crc = Crc::compute($binary, 16);
        $binary = substr($binary, 0, 12).pack('V', $crc).$compData;

        $result = RtfDecompressor::decompress($binary);
        $this->assertSame('', $result);
    }

    public function testBackRefEndMarker(): void
    {
        // A back-reference where refOffset === writeOffset (207) signals end of stream
        // writeOffset starts at 207; refOffset = ref['value'] >> 4 = 207 → 207 << 4 = 3312
        $compData = "\x01".pack('n', 207 << 4); // refOffset=207=writeOffset → canRun=false
        $rawSize = 1;
        $compSize = strlen($compData) + 12;

        $header = pack('V', $compSize)
            .pack('V', $rawSize)
            .pack('V', 0x75465A4C)
            .pack('V', 0);

        $binary = $header.$compData;
        $crc = Crc::compute($binary, 16);
        $binary = substr($binary, 0, 12).pack('V', $crc).$compData;

        $result = RtfDecompressor::decompress($binary);
        $this->assertSame('', $result);
    }

    public function testLiteralAtEndOfCompressedDataStopsCleanly(): void
    {
        $compData = "\x00";
        $binary = $this->compressedPayload($compData, 1);

        $this->assertSame('', RtfDecompressor::decompress($binary));
    }

    public function testBackReferenceStopsAtDeclaredOutputSize(): void
    {
        $compData = "\x01".pack('n', 0x000F);
        $binary = $this->compressedPayload($compData, 1);

        $this->assertSame('{', RtfDecompressor::decompress($binary));
    }

    private function compressedPayload(string $data, int $rawSize): string
    {
        $header = pack('V', strlen($data) + 12)
            .pack('V', $rawSize)
            .pack('V', 0x75465A4C)
            .pack('V', 0);
        $binary = $header.$data;

        return substr($binary, 0, 12).pack('V', Crc::compute($binary, 16)).$data;
    }
}
