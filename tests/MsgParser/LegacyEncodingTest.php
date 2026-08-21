<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\MsgParser;

use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\Writer\CompoundBuilder;
use PHPUnit\Framework\TestCase;

final class LegacyEncodingTest extends TestCase
{
    public function testUsesRussianLocaleForLegacyCp1251StringEightProperties(): void
    {
        $subject = 'Проверка старого Outlook';
        $body = "Русский текст без Unicode.\r\n";
        $fileName = 'договор-приложение.txt';
        $source = new CompoundBuilder();
        $root = $source->rootIndex();
        $source->addStream(
            '__properties_version1.0',
            str_repeat("\0", 32)
                .$this->integerProperty('3FF1', 1049)
                .$this->stringProperty('0037', $subject, 'Windows-1251')
                .$this->stringProperty('1000', $body, 'Windows-1251'),
            $root,
        );
        $source->addStream('__substg1.0_0037001E', mb_convert_encoding($subject."\0", 'Windows-1251', 'UTF-8'), $root);
        $source->addStream('__substg1.0_1000001E', mb_convert_encoding($body."\0", 'Windows-1251', 'UTF-8'), $root);

        $attachment = $source->addStorage('__attach_version1.0_#00000000', $root);
        $source->addStream(
            '__properties_version1.0',
            str_repeat("\0", 8)
                .$this->integerProperty('3705', 1)
                .$this->stringProperty('3707', $fileName, 'Windows-1251'),
            $attachment,
        );
        $source->addStream('__substg1.0_3707001E', mb_convert_encoding($fileName."\0", 'Windows-1251', 'UTF-8'), $attachment);
        $source->addStream('__substg1.0_37010102', 'данные', $attachment);

        $message = Message::from($source->build());
        $this->assertSame($subject, $message->subject());
        $this->assertSame($body, $message->body());
        $this->assertSame($fileName, $message->attachments[0]->name());

        $rewritten = Message::from($message->toBuilder()->subject($subject.' — сохранено')->toBinary());
        $this->assertSame($subject.' — сохранено', $rewritten->subject());
        $this->assertSame($body, $rewritten->body());
        $this->assertSame($fileName, $rewritten->attachments[0]->name());
    }

    public function testFallsBackToCp936ForMislabeledStringEightAttachmentNames(): void
    {
        $expected = '测试样本中文附件名称占位用例数据内容补齐字段.docx';
        $source = new CompoundBuilder();
        $source->addStream('__properties_version1.0', str_repeat("\0", 32), $source->rootIndex());

        $attachment = $source->addStorage('__attach_version1.0_#00000000', $source->rootIndex());
        $properties = str_repeat("\0", 8)
            .$this->integerProperty('3705', 1)
            .$this->integerProperty('3FDE', 65001);
        $source->addStream('__properties_version1.0', $properties, $attachment);
        $source->addStream('__substg1.0_3707001E', mb_convert_encoding($expected."\0", 'CP936', 'UTF-8'), $attachment);
        $source->addStream('__substg1.0_37010102', 'payload', $attachment);

        $message = Message::from($source->build());
        $this->assertSame($expected, $message->attachments[0]->name());

        $rewritten = Message::from($message->toBuilder()->subject('Changed')->toBinary());
        $this->assertSame($expected, $rewritten->attachments[0]->name());
        $this->assertSame('payload', $rewritten->attachments[0]->data());
    }

    public function testHonorsTheCharsetDeclaredInsideBinaryHtml(): void
    {
        $html = '<html><meta charset="gbk"><body>中文正文</body></html>';
        $encoded = mb_convert_encoding($html, 'CP936', 'UTF-8');
        $message = Message::from(Message::make('HTML')->html($encoded)->toBinary());

        $this->assertSame($html, $message->bodyHtml());
    }

    public function testDecodesUtf16HtmlBomAndRemovesTheMapiTerminator(): void
    {
        $html = '<html><body>Привет</body></html>';
        $encoded = "\xFF\xFE".mb_convert_encoding($html."\0", 'UTF-16LE', 'UTF-8');
        $message = Message::from(Message::make('HTML')->html($encoded)->toBinary());

        $this->assertSame($html, $message->bodyHtml());
    }

    private function integerProperty(string $id, int $value): string
    {
        return pack('V', (hexdec($id) << 16) | 0x0003)
            .pack('V', 0x00000006)
            .pack('V', $value)
            .pack('V', 0);
    }

    /**
     * Encode a variable String8 property record using the given legacy codepage.
     */
    private function stringProperty(string $id, string $value, string $encoding): string
    {
        $encoded = mb_convert_encoding($value."\0", $encoding, 'UTF-8');

        return pack('V', (hexdec($id) << 16) | 0x001E)
            .pack('V', 0x00000006)
            .pack('V', strlen($encoded))
            .pack('V', 0);
    }
}
