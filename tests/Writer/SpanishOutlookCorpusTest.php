<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\Writer\MessageBuilderFingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SpanishOutlookCorpusTest extends TestCase
{
    private const EXPECTED_FILES = 70;

    /**
     * Ensure every Spanish Outlook fixture can be read and rewritten without changing its bytes or semantics.
     */
    #[DataProvider('fixtureProvider')]
    public function testFixtureRoundTripsThroughThePublicApi(string $path): void
    {
        $original = file_get_contents($path);

        $this->assertIsString($original, $path);

        $source = Message::from($original);
        $rewritten = $source->toBinary();
        $reparsed = Message::from($rewritten);

        $this->assertSame($original, $rewritten, $path);
        $this->assertSame(hash('sha256', $original), hash('sha256', $rewritten), $path);
        $this->assertSame(
            MessageBuilderFingerprint::make($source->toBuilder()),
            MessageBuilderFingerprint::make($reparsed->toBuilder()),
            $path,
        );
    }

    /**
     * Ensure every source and rewritten fixture has a valid Compound File Binary structure.
     */
    #[DataProvider('fixtureProvider')]
    public function testFixtureAndRewrittenBinaryPassSevenZipValidation(string $path): void
    {
        $sevenZip = (new ExecutableFinder())->find('7z');
        if ($sevenZip === null) {
            if (getenv('CI') !== false) {
                $this->fail('7z is required in CI but was not found.');
            }

            $this->markTestSkipped('7z is not installed.');
        }

        $original = file_get_contents($path);
        $this->assertIsString($original, $path);

        $rewrittenPath = tempnam(sys_get_temp_dir(), 'php-msg-spanish-');
        $this->assertIsString($rewrittenPath);

        try {
            $this->assertSame(strlen($original), file_put_contents($rewrittenPath, Message::from($original)->toBinary()));

            foreach ([$path, $rewrittenPath] as $validatedPath) {
                $process = new Process([$sevenZip, 't', '-y', $validatedPath]);
                $process->setTimeout(30);
                $process->mustRun();

                $this->assertStringContainsString('Everything is Ok', $process->getOutput(), $validatedPath);
            }
        } finally {
            if (is_file($rewrittenPath)) {
                unlink($rewrittenPath);
            }
        }
    }

    /**
     * Provide every fixture in the Spanish Outlook compatibility corpus.
     *
     * @return iterable<string, array{string}>
     */
    public static function fixtureProvider(): iterable
    {
        $paths = glob(dirname(__DIR__).'/Fixtures/spanish-outlook-corpus/*.msg');

        self::assertIsArray($paths);
        sort($paths, SORT_STRING);
        self::assertCount(self::EXPECTED_FILES, $paths);

        foreach ($paths as $path) {
            yield basename($path) => [$path];
        }
    }
}
