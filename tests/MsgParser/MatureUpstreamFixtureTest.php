<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\MsgParser;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\AttachmentMethod;
use Cosmira\OutlookMessage\Message;
use PHPUnit\Framework\TestCase;

final class MatureUpstreamFixtureTest extends TestCase
{
    public function testDecodesHiraokaShiftJisBodyUsingTheMessageCodepage(): void
    {
        $message = $this->fixture('hiraoka-msgreader', 'nonUnicodeCP932.msg');

        $this->assertSame('日本語 Non Unicode タイトル', $message->subject());
        $this->assertSame("日本語 Non Unicode 本文\r\n", $message->body());
        $this->assertSame(['xmailuser2@xmailserver.test'], $message->to()->pluck('email')->all());

        $newOutlook = $this->fixture('hiraoka-msgreader', 'newOutlook Microsoft Outlook test.msg');
        $this->assertSame('Microsoft Outlook テスト メッセージ', $newOutlook->subject());
        $this->assertStringContainsString('Microsoft Outlook', (string) $newOutlook->bodyHtml());
    }

    public function testReadsHiraokaRecipientInlineAndOrderedAttachmentOracles(): void
    {
        $recipients = $this->fixture('hiraoka-msgreader', 'Subject.msg');
        $this->assertSame(['to@example.com'], $recipients->to()->pluck('email')->all());
        $this->assertSame(['cc@example.com'], $recipients->cc()->pluck('email')->all());
        $this->assertSame(['bcc@example.com'], $recipients->bcc()->pluck('email')->all());

        $inline = $this->fixture('hiraoka-msgreader', 'attachAndInline.msg');
        $this->assertCount(2, $inline->attachments);
        $this->assertFalse($inline->attachments[0]->isInline());
        $this->assertTrue($inline->attachments[1]->isInline());
        $this->assertSame('image001.png@01D78380.EF6DC500', $inline->attachments[1]->contentId());

        $ordered = $this->fixture('hiraoka-msgreader', 'attachmentsOrder.msg');
        $this->assertSame(
            ['A.docx', 'B.docx', 'C.docx', 'D.docx'],
            array_map(static fn (Attachment $attachment): ?string => $attachment->name(), $ordered->attachments),
        );
        foreach ($ordered->attachments as $attachment) {
            $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $attachment->mime());
        }
    }

    public function testReadsHiraokaNestedLargeFatDifatAndHighCardinalityFiles(): void
    {
        $nested = $this->fixture('hiraoka-msgreader', 'msgInMsgInMsg.msg');
        $firstLevel = $nested->attachments[0]->message();
        $this->assertInstanceOf(Message::class, $firstLevel);
        $this->assertSame('I have attachments!', $firstLevel->subject());
        $secondLevel = $firstLevel->attachments[0]->message();
        $this->assertInstanceOf(Message::class, $secondLevel);
        $this->assertSame('Microsoft Outlook テスト メッセージ', $secondLevel->subject());
        $this->assertStringContainsString('この電子メール', (string) $secondLevel->body());

        foreach ([
            'longerFat.msg'   => ['Has 64KB.bin', 91_136],
            'longerDifat.msg' => ['Has 8MiB.bin', 8_480_768],
        ] as $name => [$subject, $size]) {
            $message = $this->fixture('hiraoka-msgreader', $name);
            $this->assertSame(AttachmentMethod::EmbeddedMessage, $message->attachments[0]->method());
            $this->assertSame($size, strlen($message->attachments[0]->data()));
            $this->assertSame($subject, $message->attachments[0]->message()?->subject());
        }

        $manyRecipients = $this->fixture('hiraoka-msgreader', '200 recipients.msg');
        $this->assertCount(200, $manyRecipients->recipients);

        $manyEntities = $this->fixture('hiraoka-msgreader', 'Many entities.msg');
        $this->assertCount(22, $manyEntities->attachments);
        foreach ($manyEntities->attachments as $attachment) {
            $this->assertSame(AttachmentMethod::EmbeddedMessage, $attachment->method());
            $this->assertInstanceOf(Message::class, $attachment->message());
        }
    }

    public function testReadsHiraokaMessageClassesAndEncodingPairs(): void
    {
        foreach ([
            'A schedule.msg'     => 'IPM.Appointment',
            'A memo.msg'         => 'IPM.StickyNote',
            'contactAnsi.msg'    => 'IPM.Contact',
            'contactUnicode.msg' => 'IPM.Contact',
            'voteItems.msg'      => 'IPM.Note',
        ] as $name => $class) {
            $this->assertSame($class, $this->fixture('hiraoka-msgreader', $name)->messageClass(), $name);
        }

        $ansi = $this->fixture('hiraoka-msgreader', 'contactAnsi.msg');
        $unicode = $this->fixture('hiraoka-msgreader', 'contactUnicode.msg');
        $this->assertSame('コム ドット イグザンプル 殿', $ansi->subject());
        $this->assertSame($ansi->subject(), $unicode->subject());
    }

    public function testReadsRubyMsgClassesAndPreservesNamedPropertyMappings(): void
    {
        $post = $this->fixture('ruby-msg', 'test_Blammo.msg');
        $this->assertSame('IPM.Post', $post->messageClass());
        $this->assertSame('BlammoBlammo', $post->subject());
        $this->assertSame('TripleNickel', $post->senderName());
        $this->assertSame('TripleNickel@mapi32.net', $post->senderEmail());
        $this->assertNotEmpty($post->nameIdStreams);

        $this->assertSame('IPM.StickyNote', $this->fixture('ruby-msg', 'note.msg')->messageClass());
        foreach (['contact-plain.msg', 'contact-unicode.msg', 'Swetlana Novikova.msg'] as $name) {
            $contact = $this->fixture('ruby-msg', $name);
            $this->assertSame('IPM.Contact', $contact->messageClass(), $name);
            $this->assertNotEmpty($contact->nameIdStreams, $name);
        }

        $original = $this->fixture('ruby-msg', 'qwerty_1-orig.msg');
        $custom = $this->fixture('ruby-msg', 'qwerty_2-with_custom_properties.msg');
        $this->assertCount(1, $original->recipients);
        $this->assertCount(3, $custom->recipients);
        $this->assertNotEmpty($custom->rawProperties());
        $this->assertNotEmpty($custom->nameIdStreams);
    }

    public function testReadsVikramLargeMixedAndNestedMessages(): void
    {
        $complete = $this->fixture('vikram-msg-parser', 'complete.msg');
        $this->assertSame('Test Multiple attachments complete email!!', $complete->subject());
        $this->assertSame('admin@fegraph.onmicrosoft.com', $complete->senderEmail());
        $this->assertCount(4, $complete->recipients);
        $this->assertCount(10, $complete->attachments);
        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(AttachmentMethod::EmbeddedMessage, $complete->attachments[$i]->method());
            $this->assertInstanceOf(Message::class, $complete->attachments[$i]->message());
        }

        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $complete->attachments[6]->mime());
        $this->assertSame('text/plain', $complete->attachments[7]->mime());
        $this->assertSame('application/pdf', $complete->attachments[8]->mime());
        $this->assertSame('image/jpeg', $complete->attachments[9]->mime());

        $delivery = $this->fixture('vikram-msg-parser', 'other.msg');
        $this->assertSame('投递状态通知 (Failure Notice)', $delivery->subject());
        $this->assertSame('default_attachment.eml', $delivery->attachments[0]->name());
        $this->assertStringStartsWith('回复：转发：反馈', (string) $delivery->attachments[0]->message()?->subject());

        $outer = $this->fixture('vikram-msg-parser', 'outer.msg');
        $this->assertSame('outer subject', $outer->subject());
        $this->assertSame('test', $outer->attachments[0]->message()?->subject());
    }

    public function testEveryAdditionalMatureFixtureRoundTripsByteIdentically(): void
    {
        foreach ([
            'hiraoka-msgreader'  => 44,
            'ruby-msg'           => 8,
            'vikram-msg-parser'  => 3,
        ] as $source => $expectedCount) {
            $paths = glob($this->fixturePath($source, '*.msg'));
            $this->assertIsArray($paths);
            if ($source === 'hiraoka-msgreader') {
                $nestedPaths = glob($this->fixturePath($source, 'data/*.msg'));
                $this->assertIsArray($nestedPaths);
                $paths = array_merge($paths, $nestedPaths);
            }

            sort($paths);

            $this->assertCount($expectedCount, $paths, $source);
            foreach ($paths as $path) {
                $binary = file_get_contents($path);
                $this->assertIsString($binary, $path);
                $this->assertSame($binary, Message::from($binary)->toBinary(), sprintf('Round-trip changed %s.', $path));
            }
        }
    }

    private function fixture(string $source, string $name): Message
    {
        return Message::fromPath($this->fixturePath($source, $name));
    }

    private function fixturePath(string $source, string $name): string
    {
        return __DIR__.'/../Fixtures/'.$source.'/'.$name;
    }
}
