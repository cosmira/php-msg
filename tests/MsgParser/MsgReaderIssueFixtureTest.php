<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\MsgParser;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\AttachmentMethod;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\Recipient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class MsgReaderIssueFixtureTest extends TestCase
{
    private const EXPECTED_FILES = 71;

    /**
     * Ensure every public issue sample survives both the preserving and freshly serialized paths.
     */
    #[DataProvider('fixtureProvider')]
    public function testEveryIssueFixtureParsesAndRoundTrips(string $path): void
    {
        $binary = file_get_contents($path);
        $this->assertIsString($binary);

        $original = Message::fromPath($path);
        $this->assertSame($binary, $original->toBinary(), $path);

        $subject = $original->subject();
        $rewritten = Message::from(
            $original->toBuilder()->subject(($subject ?? '').' [round-trip]')->toBinary(),
        );

        $this->assertSame(($subject ?? '').' [round-trip]', $rewritten->subject(), $path);
        $this->assertSame($this->semanticSnapshot($original, false), $this->semanticSnapshot($rewritten, false), $path);
    }

    /**
     * Verify representative legacy and malformed encodings against their reporter-provided values.
     */
    public function testDecodesKnownLegacyEncodingRegressions(): void
    {
        $this->assertSame('Привет', $this->fixture('32/Привет.msg')->subject());
        $this->assertSame('test distribution list правда', $this->fixture('104/test_distribution_list.msg')->subject());
        $this->assertSame('日本語タイトル', $this->fixture('328/Japanese ANSI.msg')->subject());
        $this->assertSame("日本語本文\r\n", $this->fixture('328/Japanese ANSI.msg')->body());
        $this->assertSame('öäéàèü Test chars', $this->fixture('328/ Test chars.msg')->subject());
        $this->assertSame('Re: Testing On Foreign  Language Issue', $this->fixture('520/520-Test.msg')->subject());
        $this->assertSame(" \r\nあああ\r\n", $this->fixture('529/529-test1.msg')->body());

        $gbk = $this->fixture('521/521-EmailWithGbkAttachmentNameTaggedUtf8.msg');
        $this->assertSame('测试样本中文附件名称占位用例数据内容补齐字段.docx', $gbk->attachments[1]->name());
    }

    /**
     * Verify malformed RTF and legacy OLE attachments are accepted without becoming embedded messages.
     */
    public function testParsesKnownRtfAndOleStorageRegressions(): void
    {
        foreach (['31/broken_rtf.msg', '198/rtf_bug_example.msg', '298/20 Testdokument Zeile 20.msg', '402/Beispiel-E-Mail RTF.msg'] as $name) {
            $message = $this->fixture($name);

            $this->assertStringStartsWith('{\\rtf', (string) $message->bodyRtf(), $name);
            $this->assertContains(AttachmentMethod::Storage, array_map(
                static fn (Attachment $attachment): ?AttachmentMethod => $attachment->method(),
                $message->attachments,
            ), $name);
        }

        $minimal = $this->fixture('530/530-minimal.msg');
        $this->assertNull($minimal->subject());
        $this->assertSame('{\\rtf1\\a-b}', $minimal->bodyRtf());
    }

    /**
     * Validate every original and freshly serialized issue sample with bounded memory and 7-Zip.
     */
    public function testEveryIssueFixtureProducesAValidCompoundFileWithinTheMemoryBudget(): void
    {
        $sevenZip = (new ExecutableFinder())->find('7z');
        if ($sevenZip === null) {
            if (getenv('CI') !== false) {
                $this->fail('7z is required in CI but was not found.');
            }

            $this->markTestSkipped('7z is not installed.');
        }

        $directory = sys_get_temp_dir().'/php-msg-issues-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory));
        $baseline = memory_get_usage(true);
        $maximum = $baseline;

        try {
            foreach (self::fixtureProvider() as $name => [$path]) {
                $message = Message::fromPath($path);
                $message->toBuilder()
                    ->subject(($message->subject() ?? '').' [validated]')
                    ->save($directory.'/'.str_replace(['/', '\\'], '-', $name));
                unset($message);
                gc_collect_cycles();
                $maximum = max($maximum, memory_get_usage(true));
            }

            $this->assertLessThanOrEqual(32 * 1024 * 1024, $maximum - $baseline);
            $this->assertSevenZipCorpus($sevenZip, dirname(__DIR__).'/Fixtures/msg-reader/issues/*/*.msg');
            $this->assertSevenZipCorpus($sevenZip, $directory.'/*.msg');
        } finally {
            $paths = glob($directory.'/*.msg');
            foreach (is_array($paths) ? $paths : [] as $path) {
                unlink($path);
            }

            rmdir($directory);
        }
    }

    /**
     * Provide every unique MSG attached to a parsing-related MSGReader issue.
     *
     * @return iterable<string, array{string}>
     */
    public static function fixtureProvider(): iterable
    {
        $root = dirname(__DIR__).'/Fixtures/msg-reader/issues';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $paths = [];

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }

            if ($file->isFile() && strtolower($file->getExtension()) === 'msg') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths, SORT_STRING);
        self::assertCount(self::EXPECTED_FILES, $paths);

        foreach ($paths as $path) {
            yield substr($path, strlen($root) + 1) => [$path];
        }
    }

    /**
     * Build a stable snapshot of all semantics unrelated to the deliberately changed subject.
     *
     * @return array<string, mixed>
     */
    private function semanticSnapshot(Message $message, bool $includeSubject = true): array
    {
        return [
            'subject'     => $includeSubject ? $message->subject() : null,
            'senderName'  => $message->senderName(),
            'senderEmail' => $message->senderEmail(),
            'body'        => $message->body(),
            'bodyHtml'    => $message->bodyHtml(),
            'bodyRtf'     => $message->bodyRtf(),
            'headers'     => $message->headers(),
            'recipients'  => array_map(static fn (Recipient $recipient): array => [
                'name'  => $recipient->name(),
                'email' => $recipient->email(),
                'type'  => $recipient->type(),
            ], $message->recipients),
            'attachments' => array_map(fn (Attachment $attachment): array => [
                'name'     => $attachment->name(),
                'mime'     => $attachment->mime(),
                'method'   => $attachment->method()?->value,
                'content'  => $attachment->method() === AttachmentMethod::ByValue ? $attachment->hash() : null,
                'message'  => $attachment->message() instanceof Message
                    ? $this->semanticSnapshot($attachment->message())
                    : null,
            ], $message->attachments),
        ];
    }

    /**
     * Read one reporter-provided MSG fixture through the public path API.
     */
    private function fixture(string $name): Message
    {
        return Message::fromPath(dirname(__DIR__).'/Fixtures/msg-reader/issues/'.$name);
    }

    /**
     * Require 7-Zip to recognize every file selected by the given archive wildcard.
     */
    private function assertSevenZipCorpus(string $sevenZip, string $pattern): void
    {
        $process = new Process([$sevenZip, 't', '-y', $pattern]);
        $process->setTimeout(60);
        $process->mustRun();

        $this->assertStringContainsString('Archives: '.self::EXPECTED_FILES, $process->getOutput());
        $this->assertStringNotContainsString('Archives with Errors', $process->getOutput());
    }
}
