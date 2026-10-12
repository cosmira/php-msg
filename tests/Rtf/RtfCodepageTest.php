<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Rtf;

use Cosmira\OutlookMessage\Rtf\RtfCodepage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RtfCodepageTest extends TestCase
{
    /**
     * These codepages are unsupported by mbstring.
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

    /**
     * @return iterable<string, array{string, int, string, bool}>
     */
    public static function optionalConverterProvider(): iterable
    {
        foreach (self::legacyCodepageProvider() as $name => [$bytes, $codepage, $expected]) {
            yield $name.' via iconv' => [$bytes, $codepage, $expected, true];
        }

        yield 'without either converter' => ["\xE8\xE1", 1250, 'èá', false];
    }

    #[DataProvider('optionalConverterProvider')]
    public function testDecodingWithoutIntl(string $bytes, int $codepage, string $expected, bool $withIconv): void
    {
        if ($withIconv && ! function_exists('iconv')) {
            $this->markTestSkipped('iconv is required for this converter profile.');
        }

        // Isolate the namespaced availability stub from the main PHPUnit process.
        // The iconv profile uses the real extension; the final fallback disables it.
        $script = <<<'PHP'
            namespace Cosmira\OutlookMessage\Rtf;

            function class_exists(string $class): bool
            {
                return $class !== \UConverter::class && \class_exists($class);
            }

            require $argv[1];
            echo RtfCodepage::decode(hex2bin($argv[2]), (int) $argv[3]);
            PHP;

        $process = new Process([
            PHP_BINARY, '-d', 'disable_functions='.($withIconv ? '' : 'iconv'),
            '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', bin2hex($bytes), (string) $codepage,
        ]);
        $process->mustRun();

        $this->assertSame($expected, $process->getOutput());
    }
}
