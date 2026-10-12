<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Rtf;

use Cosmira\OutlookMessage\Rtf\RtfCodepage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UConverter;

final class RtfCodepageTest extends TestCase
{
    /**
     * These codepages are unsupported by mbstring. CI also runs this provider without intl.
     *
     * @return iterable<string, array{string, int, string}>
     */
    public static function legacyCodepageProvider(): iterable
    {
        yield 'windows-1250' => ["\xE8\xE1", 1250, 'čá'];
        yield 'windows-1253' => ["\xE1\xE2", 1253, 'αβ'];
        yield 'windows-1255' => ["\xF9\xEC\xE5\xED", 1255, 'שלום'];
        yield 'mac-roman' => ["\x8A", 10000, 'ä'];
    }

    #[DataProvider('legacyCodepageProvider')]
    public function testDecodesLegacyCodepages(string $bytes, int $codepage, string $expected): void
    {
        $this->assertSame($expected, RtfCodepage::decode($bytes, $codepage));
    }

    public function testUnknownCodepageFallsBackToWindows1252(): void
    {
        $this->assertSame('€für', RtfCodepage::decode("\x80f\xFCr", 99999));
    }

    public function testMalformedUtf8IsScrubbed(): void
    {
        $this->assertTrue(mb_check_encoding(RtfCodepage::decode("\xFFtext", 65001), 'UTF-8'));
    }

    public function testUnavailableConvertersFallBackToWindows1252(): void
    {
        if (class_exists(UConverter::class) || function_exists('iconv')) {
            $this->markTestSkipped('This fallback requires both optional converters to be unavailable.');
        }

        $this->assertSame('èá', RtfCodepage::decode("\xE8\xE1", 1250));
    }
}
