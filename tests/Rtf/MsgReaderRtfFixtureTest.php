<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Rtf;

use Cosmira\OutlookMessage\Rtf\RtfDecompressor;
use PHPUnit\Framework\TestCase;

final class MsgReaderRtfFixtureTest extends TestCase
{
    public function testDecompressesUpstreamLzfuAndMelaSamples(): void
    {
        $lzfu = RtfDecompressor::decompress($this->fixture('LZFu.bin'));
        $mela = RtfDecompressor::decompress($this->fixture('MELA.bin'));
        $expected = $this->fixture('MELA.rtf');

        $this->assertSame($expected, $mela);
        $this->assertSame($this->normalizeNewlines($expected), $this->normalizeNewlines($lzfu));
        $this->assertSame($this->normalizeNewlines($this->fixture('LZFu.rtf')), $this->normalizeNewlines($lzfu));
    }

    public function testUncompressedMelaAcceptsTheKnownTwelveByteSizeMismatch(): void
    {
        $rtf = '{\rtf1\ansi\ansicpg1252\deff0{\fonttbl{\f0\fswiss Helvetica;}}'
            .'\uc1\pard\plain\f0\fs20 Hello world.\par }';
        $compressedSize = strlen($rtf) + 12;
        $binary = pack('V4', $compressedSize, $compressedSize, 0x414C454D, 0).$rtf;

        $this->assertSame($rtf, RtfDecompressor::decompress($binary));
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/msg-reader/rtf/'.$name);
        $this->assertIsString($contents);

        return $contents;
    }

    private function normalizeNewlines(string $value): string
    {
        return str_replace(["\r\n", "\n\r"], "\n", $value);
    }
}
