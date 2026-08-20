<?php

declare(strict_types=1);

use Cosmira\OutlookMessage\Tests\Outlook\OutlookAcceptanceCorpus;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$outputDirectory = $argv[1] ?? null;
if (! is_string($outputDirectory) || $outputDirectory === '') {
    throw new InvalidArgumentException('Usage: php tests/Outlook/VerifyResaved.php <output-directory>');
}

$manifestPath = $outputDirectory.DIRECTORY_SEPARATOR.'manifest.json';
$json = file_get_contents($manifestPath);
if (! is_string($json)) {
    throw new RuntimeException(sprintf('Unable to read %s.', $manifestPath));
}

/** @var array{schema: int, cases: list<array<string, mixed>>} $manifest */
$manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
OutlookAcceptanceCorpus::verifyResaved($outputDirectory, $manifest);

printf("Verified %d MSG files saved by Classic Outlook.\n", count($manifest['cases']));
