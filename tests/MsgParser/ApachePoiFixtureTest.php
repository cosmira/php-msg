<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\MsgParser;

use Cosmira\OutlookMessage\Exception\ParseException;
use Cosmira\OutlookMessage\Message;
use PHPUnit\Framework\TestCase;

final class ApachePoiFixtureTest extends TestCase
{
    public function testDecodesApachePoiCodepageRegressions(): void
    {
        $cp1251 = $this->fixture('ASCII_CP1251_LCID1049.msg');
        $this->assertSame('Subject автоматически Subject', $cp1251->subject());
        $this->assertSame('Body автоматически Body', $cp1251->body());
        $this->assertSame(
            '<!DOCTYPE html><html><meta charset=\"windows-1251\"><body>HTML автоматически</body></html>',
            $cp1251->bodyHtml(),
        );

        $cp1252 = $this->fixture('ASCII_UTF-8_CP1252_LCID1031.msg');
        $this->assertSame('Subject öäü Subject', $cp1252->subject());
        $this->assertSame('Body öäü Body', $cp1252->body());
        $this->assertNull($cp1252->bodyHtml());

        $cp1252WithHtml = $this->fixture('ASCII_UTF-8_CP1252_LCID1031_HTML.msg');
        $this->assertSame('Subject öäü Subject', $cp1252WithHtml->subject());
        $this->assertSame('Body öäü Body', $cp1252WithHtml->body());
        $this->assertSame(
            '<!DOCTYPE html><html><meta charset=\"utf-8\"><body>HTML öäü</body></html>',
            $cp1252WithHtml->bodyHtml(),
        );

        $binaryCp1251 = $this->fixture('HTMLBodyBinary_CP1251.msg');
        $this->assertSame('Subject öäü Subject', $binaryCp1251->subject());
        $this->assertNull($binaryCp1251->body());
        $this->assertSame(
            '<!DOCTYPE html><html><meta charset=\"utf-8\"><body>HTML автоматически</body></html>',
            $binaryCp1251->bodyHtml(),
        );

        $binaryUtf8 = $this->fixture('HTMLBodyBinary_UTF-8.msg');
        $this->assertSame('Subject öäü Subject', $binaryUtf8->subject());
        $this->assertNull($binaryUtf8->body());
        $this->assertSame(
            '<!DOCTYPE html><html><meta charset=\"utf-8\"><body>HTML öäü</body></html>',
            $binaryUtf8->bodyHtml(),
        );
    }

    public function testReadsCanonicalSimpleAndOutlook30Messages(): void
    {
        $simple = $this->fixture('simple_test_msg.msg');
        $this->assertSame('test message', $simple->subject());
        $this->assertSame('test message', $simple->conversationTopic());
        $this->assertSame('IPM.Note', $simple->messageClass());
        $this->assertSame('Travis Ferguson', $simple->senderName());
        $this->assertSame('travis@overwrittenstack.com', $simple->senderEmail());
        $this->assertSame('This is a test message.', $simple->body());
        $this->assertSame(['travis@overwrittenstack.com'], $simple->to()->pluck('email')->all());

        $outlook30 = $this->fixture('outlook_30_msg.msg');
        $this->assertSame('IN-SPIRE servers going down for a bit, back up around 8am', $outlook30->subject());
        $this->assertSame('Cramer, Nick', $outlook30->senderName());
        $this->assertCount(18, $outlook30->recipients);
        $this->assertStringStartsWith('I am shutting down', (string) $outlook30->body());
        $this->assertStringStartsWith('<!DOCTYPE', (string) $outlook30->bodyHtml());
        $this->assertStringStartsWith('{\\rtf1', (string) $outlook30->bodyRtf());
        $this->assertStringContainsString('Microsoft Mail Internet Headers', (string) $outlook30->headers());

        $blank = $this->fixture('blank.msg');
        $this->assertSame('', $blank->subject());
        $this->assertNull($blank->senderName());
        $this->assertCount(0, $blank->recipients);
    }

    public function testReadsFixedPropertiesInternationalTextAndRecipientGroups(): void
    {
        $this->assertSame(
            '2012-06-22T18:32:54+00:00',
            $this->fixture('53784_succeeds.msg')->date()?->format(DATE_ATOM),
        );
        $this->assertSame(
            '2012-06-21T14:14:04+00:00',
            $this->fixture('53784_fails.msg')->date()?->format(DATE_ATOM),
        );

        $chinese = $this->fixture('chinese-traditional.msg');
        $this->assertSame('Tests Chang@FT (張毓倫)', $chinese->senderName());
        $this->assertStringContainsString('( MSG 格式測試 )', (string) $chinese->body());

        foreach (['example_sent_regular.msg', 'example_sent_unicode.msg'] as $name) {
            $message = $this->fixture($name);
            $this->assertSame('Mike Farman', $message->senderName(), $name);
            $this->assertCount(3, $message->to(), $name);
            $this->assertCount(3, $message->cc(), $name);
            $this->assertCount(2, $message->bcc(), $name);
        }

        foreach (['example_received_regular.msg', 'example_received_unicode.msg'] as $name) {
            $message = $this->fixture($name);
            $this->assertCount(3, $message->to(), $name);
            $this->assertCount(3, $message->cc(), $name);
            $this->assertCount(0, $message->bcc(), $name);
        }

        $multipleFixedProperties = $this->fixture('poifs/MailSentPropertyMultiple.msg');
        $this->assertSame('2024-09-04T08:11:14+00:00', $multipleFixedProperties->date()?->format(DATE_ATOM));
        $this->assertNotEmpty($multipleFixedProperties->rawProperties());
    }

    public function testReadsMessageClassesAndPreservesThemWhenEditing(): void
    {
        $classes = [
            'msgClassAppointment.msg' => 'IPM.Appointment',
            'msgClassContact.msg'     => 'IPM.Contact',
            'msgClassPost.msg'        => 'IPM.Post',
            'msgClassStickyNote.msg'  => 'IPM.StickyNote',
            'msgClassTask.msg'        => 'IPM.Task',
        ];

        foreach ($classes as $name => $expected) {
            $message = $this->fixture($name);
            $this->assertSame($expected, $message->messageClass(), $name);

            $edited = Message::from($message->toBuilder()->text('Edited body')->toBinary());
            $this->assertSame($expected, $edited->messageClass(), $name.' after editing');
            $this->assertSame($message->conversationTopic(), $edited->conversationTopic(), $name.' topic');
        }
    }

    public function testReadsSubmissionIdsWithAnUnambiguousTwoDigitYearPivot(): void
    {
        foreach ([
            'message_1979.msg'                          => '1979-08-16T11:23:16+00:00',
            'message_1980.msg'                          => '1980-08-16T11:23:16+00:00',
            'message_1981.msg'                          => '1981-08-16T11:23:16+00:00',
            'message_extra_hyphen_submission_chunk.msg' => '2007-05-31T16:06:38+00:00',
            'message_normal_submission_chunk.msg'       => '2007-05-31T16:06:38+00:00',
        ] as $name => $expected) {
            $message = $this->fixture($name);
            $this->assertSame($expected, $message->date()?->format(DATE_ATOM), $name);
            $this->assertStringContainsString('l=', (string) $message->messageSubmissionId(), $name);
        }
    }

    public function testBuilderWritesMessageClassTopicAndSubmissionId(): void
    {
        $submissionId = "c=RU;a= ;p=Example;l=SERVER-260820101112Z-42\0";
        $message = Message::from(Message::make('Task subject')
            ->messageClass('IPM.Task')
            ->conversationTopic('Task conversation')
            ->submissionId($submissionId)
            ->toBinary());

        $this->assertSame('IPM.Task', $message->messageClass());
        $this->assertSame('Task conversation', $message->conversationTopic());
        $this->assertSame($submissionId, $message->messageSubmissionId());
        $this->assertSame('2026-08-20T10:11:12+00:00', $message->date()?->format(DATE_ATOM));
    }

    public function testDecodesBomMarkedUtf16BinaryHtml(): void
    {
        $html = '<p>Привет</p>';
        $utf16 = "\xFF\xFE".mb_convert_encoding($html, 'UTF-16LE', 'UTF-8');

        $message = Message::from(Message::make('UTF-16 HTML')->html($utf16)->toBinary());

        $this->assertSame($html, $message->bodyHtml());
    }

    public function testReadsRegularInlineAndEmbeddedAttachments(): void
    {
        $regular = $this->fixture('attachment_test_msg.msg');
        $this->assertCount(2, $regular->attachments);
        $this->assertSame('test-unicode.doc', $regular->attachments[0]->name());
        $this->assertSame(24064, strlen($regular->attachments[0]->data()));
        $this->assertSame('pj1.txt', $regular->attachments[1]->name());
        $this->assertSame(89, strlen($regular->attachments[1]->data()));

        $inline = $this->fixture('attachment_msg_inlineImg.msg');
        $this->assertSame([
            'image001.png@01D0A524.96D40F30',
            'image002.png@01D0A524.96D40F30',
            'image003.png@01D0A526.B4C739C0',
            'image006.jpg@01D0A526.B649E220',
        ], array_map(static fn ($attachment): ?string => $attachment->contentId(), $inline->attachments));

        $mixed = $this->fixture('attachment_msg_pdf.msg');
        $this->assertCount(2, $mixed->attachments);
        $nested = $mixed->attachments[0]->message();
        $this->assertNotNull($nested);
        $this->assertSame('Test Attachment', $nested->subject());
        $this->assertSame('2010-06-17T23:52:19+00:00', $nested->date()?->format(DATE_ATOM));
        $this->assertSame('smbprn.00009008.KdcPjl.pdf', $mixed->attachments[1]->name());
        $this->assertSame(13539, strlen($mixed->attachments[1]->data()));
    }

    public function testHandlesLargeRecipientAndNamedPropertyFixtures(): void
    {
        $large = $this->fixture('lots-of-recipients.msg');
        $this->assertCount(1321, $large->recipients);
        $this->assertCount(1, $large->attachments);

        $keywords = $this->fixture('keywords.msg');
        $this->assertNotEmpty($keywords->nameIdStreams);
        $this->assertSame(
            $this->fixtureBinary('keywords.msg'),
            $keywords->toBinary(),
            'Named-property mapping streams must survive unchanged.',
        );
    }

    public function testEveryStructurallyValidImportedFixtureParsesAndRoundTripsByteIdentically(): void
    {
        foreach ($this->validFixtureNames() as $name) {
            $binary = $this->fixtureBinary($name);
            $message = Message::from($binary);

            $this->assertSame($binary, $message->toBinary(), $name);
        }
    }

    public function testKnownMalformedApachePoiFixturesAreRejectedAsParseErrors(): void
    {
        foreach ([
            'clusterfuzz-testcase-minimized-POIHSMFFuzzer-4735011465854976.msg',
            'clusterfuzz-testcase-minimized-POIHSMFFuzzer-5336473854148608.msg',
            'poifs/unknown_properties.msg',
        ] as $name) {
            try {
                Message::from($this->fixtureBinary($name));
                $this->fail($name.' unexpectedly parsed successfully.');
            } catch (ParseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function fixture(string $name): Message
    {
        return Message::from($this->fixtureBinary($name));
    }

    private function fixtureBinary(string $name): string
    {
        $binary = file_get_contents(__DIR__.'/../Fixtures/apache-poi/'.$name);
        $this->assertIsString($binary, $name);

        return $binary;
    }

    /**
     * @return list<string>
     */
    private function validFixtureNames(): array
    {
        return [
            '51873.msg',
            '53784_fails.msg',
            '53784_succeeds.msg',
            '58214_extracted_attachment.msg',
            '58214_with_attachment.msg',
            'ASCII_CP1251_LCID1049.msg',
            'ASCII_UTF-8_CP1252_LCID1031.msg',
            'ASCII_UTF-8_CP1252_LCID1031_HTML.msg',
            'HTMLBodyBinary_CP1251.msg',
            'HTMLBodyBinary_UTF-8.msg',
            'attachment_msg_inlineImg.msg',
            'attachment_msg_pdf.msg',
            'attachment_test_msg.msg',
            'blank.msg',
            'bug66335.msg',
            'chinese-traditional.msg',
            'clusterfuzz-testcase-minimized-POIHSMFFuzzer-4848576776503296.msg',
            'cyrillic_message.msg',
            'example_received_regular.msg',
            'example_received_unicode.msg',
            'example_sent_regular.msg',
            'example_sent_unicode.msg',
            'keywords.msg',
            'logsat.com_signatures_valid.msg',
            'lots-of-recipients.msg',
            'message_1979.msg',
            'message_1980.msg',
            'message_1981.msg',
            'message_extra_hyphen_submission_chunk.msg',
            'message_normal_submission_chunk.msg',
            'msgClassAppointment.msg',
            'msgClassContact.msg',
            'msgClassPost.msg',
            'msgClassStickyNote.msg',
            'msgClassTask.msg',
            'no_recipient_address.msg',
            'outlook_30_msg.msg',
            'quick.msg',
            'simple_test_msg.msg',
            'poifs/MailSentPropertyMultiple.msg',
        ];
    }
}
