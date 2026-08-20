<?php

declare(strict_types=1);

use Cosmira\OutlookMessage\Tests\Outlook\OutlookAcceptanceCorpus;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$outputDirectory = $argv[1] ?? null;
if (! is_string($outputDirectory) || $outputDirectory === '') {
    throw new InvalidArgumentException('Usage: php tests/Outlook/GenerateFixtures.php <output-directory>');
}

$manifest = OutlookAcceptanceCorpus::generate($outputDirectory);
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$manifestPath = $outputDirectory.DIRECTORY_SEPARATOR.'manifest.json';

if (file_put_contents($manifestPath, $json."\n") === false) {
    throw new RuntimeException(sprintf('Unable to write %s.', $manifestPath));
}

printf("Generated %d Outlook acceptance cases in %s\n", count($manifest['cases']), $outputDirectory);
