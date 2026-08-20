<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Outlook;

use Cosmira\OutlookMessage\Message;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class OutlookAcceptanceCorpus
{
    private const REPLACEMENT_PREFIX = "Outlook acceptance attachment\0";

    /**
     * @var list<string>
     */
    private const CORRUPTED_FIXTURES = [
        'apache-poi/clusterfuzz-testcase-minimized-POIHSMFFuzzer-4735011465854976.msg',
        'apache-poi/clusterfuzz-testcase-minimized-POIHSMFFuzzer-5336473854148608.msg',
        'apache-poi/poifs/unknown_properties.msg',
        'msg-parser-rs/bad_outlook.msg',
    ];

    /**
     * Generate Outlook acceptance inputs and their portable expectations.
     *
     * @return array{schema: int, cases: list<array<string, mixed>>}
     */
    public static function generate(string $outputDirectory): array
    {
        self::ensureDirectory($outputDirectory);
        $cases = [];

        foreach (self::fixturePaths() as $relative => $path) {
            $source = Message::fromPath($path);
            $messageClass = $source->messageClass() ?? 'IPM.Note';
            if (! str_starts_with($messageClass, 'IPM.Note')) {
                continue;
            }

            $index = count($cases);
            $fileName = sprintf('%03d-%s.msg', $index, substr(hash('sha256', $relative), 0, 16));
            $attachmentName = sprintf('replacement-%03d.bin', $index);
            $attachmentData = self::REPLACEMENT_PREFIX.$relative."\xFF";
            $outputPath = $outputDirectory.DIRECTORY_SEPARATOR.$fileName;

            $source->toBuilder()
                ->flushAttachment()
                ->attachData($attachmentData, $attachmentName, 'application/octet-stream')
                ->save($outputPath);

            $generated = Message::fromPath($outputPath);
            $cases[] = [
                'source'           => $relative,
                'file'             => $fileName,
                'subject'          => $generated->subject() ?? '',
                'messageClass'     => $generated->messageClass() ?? 'IPM.Note',
                'attachmentName'   => $attachmentName,
                'attachmentSha256' => hash('sha256', $attachmentData),
            ];
        }

        if (count($cases) < 100) {
            throw new RuntimeException(sprintf('Outlook acceptance corpus unexpectedly contains only %d mail messages.', count($cases)));
        }

        return ['schema' => 1, 'cases' => $cases];
    }

    /**
     * Verify files that Classic Outlook opened and saved again.
     *
     * @param array{schema: int, cases: list<array<string, mixed>>} $manifest
     */
    public static function verifyResaved(string $outputDirectory, array $manifest): void
    {
        if ($manifest['schema'] !== 1) {
            throw new RuntimeException('Unsupported Outlook acceptance manifest schema.');
        }

        $errors = [];
        foreach ($manifest['cases'] as $case) {
            $fileName = self::manifestString($case, 'file');
            $path = $outputDirectory.DIRECTORY_SEPARATOR.'resaved'.DIRECTORY_SEPARATOR.$fileName;

            try {
                $message = Message::fromPath($path);
                $attachment = $message->attachments[0] ?? null;

                if (($message->subject() ?? '') !== self::manifestString($case, 'subject')) {
                    throw new RuntimeException('subject changed');
                }

                if (($message->messageClass() ?? 'IPM.Note') !== self::manifestString($case, 'messageClass')) {
                    throw new RuntimeException('message class changed');
                }

                if (count($message->attachments) !== 1 || $attachment === null) {
                    throw new RuntimeException('expected exactly one attachment');
                }

                if ($attachment->name() !== self::manifestString($case, 'attachmentName')) {
                    throw new RuntimeException('attachment name changed');
                }

                if (hash('sha256', $attachment->data()) !== self::manifestString($case, 'attachmentSha256')) {
                    throw new RuntimeException('attachment payload changed');
                }
            } catch (\Throwable $exception) {
                $errors[] = sprintf('%s: %s', $fileName, $exception->getMessage());
            }
        }

        if ($errors !== []) {
            throw new RuntimeException("Outlook-resaved MSG verification failed:\n- ".implode("\n- ", $errors));
        }
    }

    /**
     * @return array<string, string>
     */
    private static function fixturePaths(): array
    {
        $root = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures';
        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || strtolower($file->getExtension()) !== 'msg') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            if (in_array($relative, self::CORRUPTED_FIXTURES, true)) {
                continue;
            }

            $paths[$relative] = $file->getPathname();
        }

        ksort($paths);

        return $paths;
    }

    private static function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory %s.', $directory));
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    private static function manifestString(array $case, string $key): string
    {
        $value = $case[$key] ?? null;
        if (! is_string($value)) {
            throw new RuntimeException(sprintf('Manifest field %s must be a string.', $key));
        }

        return $value;
    }
}
