<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\MsgParser;

use Cosmira\OutlookMessage\Exception\CorruptedFileException;
use Cosmira\OutlookMessage\Exception\ParseException;
use Cosmira\OutlookMessage\Message;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class RtfEncapsulatedHtmlFixtureTest extends TestCase
{
    /**
     * Ensure the HTML recovered from the RTF body matches the HTML stream for messages that carry both.
     */
    #[DataProvider('htmlStreamProvider')]
    public function testRecoveredHtmlMatchesTheStoredHtmlStream(string $name): void
    {
        $message = Message::fromPath($this->fixturePath($name));

        $this->assertIsString($message->bodyHtml());
        $this->assertIsString($message->bodyHtmlFromRtf());
        $this->assertSame($this->normalize($message->bodyHtml()), $this->normalize($message->bodyHtmlFromRtf()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function htmlStreamProvider(): iterable
    {
        $names = [
            'apache-poi/53784_fails.msg',
            'apache-poi/53784_succeeds.msg',
            'apache-poi/attachment_msg_inlineImg.msg',
            'apache-poi/message_extra_hyphen_submission_chunk.msg',
            'apache-poi/message_normal_submission_chunk.msg',
            'msg-reader/issues/102/test1.msg',
            'msg-reader/issues/192/test.msg',
            'msg-reader/issues/192/This is a test.msg',
            'msg-reader/issues/271/centralmailttest.msg',
            'msg-reader/issues/365/MUM.EM.00000007.msg',
            'msg-reader/issues/365/MUM.EM.00000328.msg',
            'msgxtractr/TestMessage-ansi.msg',
            'msgxtractr/TestMessage-default.msg',
            'msgxtractr/TestMessage-unicode.msg',
            'outlook-round-trip-corpus/message-003.msg',
            'outlook-round-trip-corpus/message-007.msg',
            'outlook-round-trip-corpus/message-059.msg',
            'outlook-round-trip-corpus/message-062.msg',
            'spanish-outlook-corpus/message-058.msg',
            'spanish-outlook-corpus/message-059.msg',
            'vikram-msg-parser/complete.msg',
        ];

        foreach ($names as $name) {
            yield $name => [$name];
        }
    }

    /**
     * Ensure RTF-only messages expose readable HTML across codepages, fonts, emoji and inline images.
     */
    #[DataProvider('rtfOnlyProvider')]
    public function testRecoversHtmlFromRtfOnlyMessages(string $name, string $expected): void
    {
        $message = Message::fromPath($this->fixturePath($name));

        $this->assertNull($message->bodyHtml());
        $this->assertStringContainsString($expected, (string) $message->bodyHtmlFromRtf());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rtfOnlyProvider(): iterable
    {
        yield 'inline image content id'  => ['outlook-message-parser/forward with attachments and embedded images.msg', 'src="cid:image001.png@'];
        yield 'windows-1250 slovak'      => ['outlook-message-parser/embedded image.msg', 'organizačná'];
        yield 'windows-1251 russian'     => ['msg-reader/issues/32/Привет.msg', 'Как дела?'];
        yield 'windows-1255 hebrew'      => ['msg-reader/issues/279/Encoding Problem.msg', 'בוקר טוב'];
        yield 'gbk font in cp932 body'   => ['hiraoka-msgreader/Hello +CJK.msg', '你好'];
        yield 'chinese font charset'     => ['outlook-message-parser/chinese message garbled.msg', '背景音乐'];
        yield 'japanese font charset'    => ['msg-reader/issues/45/testmail.msg', '日本語がよくわからない'];
        yield 'utf-8 bytes in cp1252'    => ['spanish-outlook-corpus/message-070.msg', '¿Has oído'];
        yield 'emoji surrogate pair'     => ['google-magika/sample.msg', '🎉'];
    }

    /**
     * Ensure every fixture either yields valid UTF-8 HTML or null when its RTF does not encapsulate HTML.
     */
    public function testEveryFixtureYieldsValidUtf8OrNull(): void
    {
        $encapsulated = 0;

        foreach ($this->fixtures() as $path => $message) {
            $rtf = (string) $message->bodyRtf();
            $html = $message->bodyHtmlFromRtf();

            if (! str_contains($rtf, '\fromhtml1')) {
                $this->assertNull($html, $path);

                continue;
            }

            $encapsulated++;
            $this->assertIsString($html, $path);
            $this->assertTrue(mb_check_encoding($html, 'UTF-8'), $path);
            $this->assertStringContainsString('<', $html, $path);
        }

        $this->assertSame(285, $encapsulated);
    }

    public function testReturnsNullWithoutRtfBody(): void
    {
        $message = Message::from(Message::make('No RTF')->html('<p>Hello</p>')->toBinary());

        $this->assertNull($message->bodyRtf());
        $this->assertNull($message->bodyHtmlFromRtf());
    }

    /**
     * @return iterable<string, Message>
     */
    private function fixtures(): iterable
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../Fixtures', FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (strtolower($file->getExtension()) !== 'msg') {
                continue;
            }

            try {
                yield $file->getPathname() => Message::fromPath($file->getPathname());
            } catch (CorruptedFileException|ParseException) {
                continue;
            }
        }
    }

    /**
     * Remove differences that Outlook and Exchange introduce while encapsulating HTML:
     * the charset <meta> is dropped, entities are decoded, closing tags are re-cased
     * (</Style>) and whitespace around tags is rewritten.
     */
    private function normalize(?string $html): string
    {
        $html = (string) preg_replace('/<meta[^>]*http-equiv\s*=\s*"?content-type[^>]*>/i', '', (string) $html);
        $html = str_replace("\u{00A0}", ' ', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $html = (string) preg_replace('/\s+/u', ' ', $html);
        $html = (string) preg_replace('/\s*([<>])\s*/', '$1', $html);

        return mb_strtolower(trim($html));
    }

    private function fixturePath(string $name): string
    {
        return __DIR__.'/../Fixtures/'.$name;
    }
}
