<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class OutlookRoundTripCorpusTest extends TestCase
{
    private const EXPECTED_FILES = 62;

    private const MAX_PEAK_MEMORY = 32 * 1024 * 1024;

    /**
     * Ensure the complete Outlook corpus remains byte-identical, valid, and memory bounded.
     */
    public function testCompleteOutlookCorpusRoundTripsWithoutRegression(): void
    {
        $sevenZip = (new ExecutableFinder())->find('7z');
        if ($sevenZip === null) {
            if (getenv('CI') !== false) {
                $this->fail('7z is required in CI but was not found.');
            }

            $this->markTestSkipped('7z is not installed.');
        }

        $outputDirectory = sys_get_temp_dir().'/php-msg-corpus-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($outputDirectory);

        try {
            $process = new Process([
                PHP_BINARY,
                '-d',
                'memory_limit=128M',
                '-r',
                $this->workerScript(),
                dirname(__DIR__, 2).'/vendor/autoload.php',
                dirname(__DIR__).'/Fixtures/outlook-round-trip-corpus',
                $outputDirectory,
                $sevenZip,
            ]);
            $process->setTimeout(180);
            $process->mustRun();

            /** @var array{generated: int, identical: int, sha256: int, parsed: int, semantic: int, sevenZip: int, missing: int, extra: int, peakMemory: int} $result */
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(self::EXPECTED_FILES, $result['generated']);
            $this->assertSame(self::EXPECTED_FILES, $result['identical']);
            $this->assertSame(self::EXPECTED_FILES, $result['sha256']);
            $this->assertSame(self::EXPECTED_FILES, $result['parsed']);
            $this->assertSame(self::EXPECTED_FILES, $result['semantic']);
            $this->assertSame(self::EXPECTED_FILES * 2, $result['sevenZip']);
            $this->assertSame(0, $result['missing']);
            $this->assertSame(0, $result['extra']);
            $this->assertLessThanOrEqual(self::MAX_PEAK_MEMORY, $result['peakMemory']);
        } finally {
            $filesystem->remove($outputDirectory);
        }
    }

    /**
     * Get the isolated corpus worker used to measure process-level peak memory.
     */
    private function workerScript(): string
    {
        return <<<'PHP'
require $argv[1];

use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\Writer\MessageBuilderFingerprint;
use Symfony\Component\Process\Process;

$source = $argv[2];
$target = $argv[3];
$sevenZip = $argv[4];
$files = array_values(array_filter(
    scandir($source),
    static fn (string $name): bool => str_ends_with(strtolower($name), '.msg'),
));
sort($files, SORT_STRING);

$result = [
    'generated' => 0,
    'identical' => 0,
    'sha256' => 0,
    'parsed' => 0,
    'semantic' => 0,
    'sevenZip' => 0,
    'missing' => 0,
    'extra' => 0,
    'peakMemory' => 0,
];

foreach ($files as $name) {
    $sourcePath = $source.'/'.$name;
    $targetPath = $target.'/'.$name;
    $original = file_get_contents($sourcePath);
    if ($original === false) {
        throw new RuntimeException('Unable to read fixture '.$name);
    }

    $sourceMessage = Message::from($original);
    $generated = $sourceMessage->toBinary();
    if (file_put_contents($targetPath, $generated) !== strlen($generated)) {
        throw new RuntimeException('Unable to write generated fixture '.$name);
    }

    $result['generated']++;
    $result['identical'] += (int) ($original === $generated);
    $result['sha256'] += (int) hash_equals(hash('sha256', $original), hash('sha256', $generated));

    $generatedMessage = Message::from($generated);
    $result['parsed']++;
    $result['semantic'] += (int) (
        MessageBuilderFingerprint::make($sourceMessage->toBuilder())
        === MessageBuilderFingerprint::make($generatedMessage->toBuilder())
    );

    foreach ([$sourcePath, $targetPath] as $path) {
        $process = new Process([$sevenZip, 't', '-y', $path]);
        $process->setTimeout(60);
        $process->mustRun();
        $result['sevenZip']++;
    }

    unset($original, $generated, $sourceMessage, $generatedMessage);
    gc_collect_cycles();
}

$generatedFiles = array_values(array_filter(
    scandir($target),
    static fn (string $name): bool => str_ends_with(strtolower($name), '.msg'),
));
sort($generatedFiles, SORT_STRING);
$result['missing'] = count(array_diff($files, $generatedFiles));
$result['extra'] = count(array_diff($generatedFiles, $files));
$result['peakMemory'] = memory_get_peak_usage(true);

echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;
    }
}
