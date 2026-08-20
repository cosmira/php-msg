<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\MsgParser;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\AttachmentMethod;
use Cosmira\OutlookMessage\Exception\ParseException;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\Recipient;
use PHPUnit\Framework\TestCase;

final class AdditionalUpstreamFixtureTest extends TestCase
{
    public function testMatchesEmailOutlookMessagePerlBodyAndAttachmentOracles(): void
    {
        $plain = $this->fixture('email-outlook-message-perl', 'plain_unsent.msg');
        $unicode = $this->fixture('email-outlook-message-perl', 'plain_uc_unsent.msg');
        $wide = $this->fixture('email-outlook-message-perl', 'plain_uc_wc_unsent.msg');

        $this->assertSame('Test for MSGConvert -- plain text', $plain->subject());
        $this->assertSame("This is a test\r\nThe body is in plain text", $plain->body());
        $this->assertSame($plain->body(), $unicode->body());
        $this->assertSame("This is a test\r\nThe body is in p汬ain text", $wide->body());
        $this->assertSame(['someone@somewhere.com'], $plain->to()->pluck('email')->all());
        $this->assertStringStartsWith('{\\rtf1', (string) $unicode->bodyRtf());

        $charset = $this->fixture('email-outlook-message-perl', 'charset.msg');
        $this->assertSame('PST Export - Embedded Email Test', $charset->subject());
        $this->assertSame("This email contains an email… Email-ception!!!\n\n", $charset->body());
        $this->assertSame('2019-10-09T05:55:10+00:00', $charset->date()?->format(DATE_ATOM));
        $this->assertStringContainsString('From: Joseph Q Bloggs <joebloggs@example.org>', (string) $charset->headers());
        $this->assertStringContainsString('Email-ception!!!', (string) $charset->bodyHtml());

        $jpeg = $this->fixture('email-outlook-message-perl', 'plain_jpeg_attached.msg');
        $this->assertSame('test', $jpeg->subject());
        $this->assertSame("test\r\n", $jpeg->body());
        $this->assertCount(1, $jpeg->attachments);
        $this->assertSame('test.jpg', $jpeg->attachments[0]->name());
        $this->assertSame('image/jpeg', $jpeg->attachments[0]->mime());
        $this->assertSame(7681, strlen($jpeg->attachments[0]->data()));
        $this->assertSame('7aa673250e2d3071dc278106f9a2478e4cf96179a44d59e11c94e255c302c9d1', hash('sha256', $jpeg->attachments[0]->data()));

        $signed = $this->fixture('email-outlook-message-perl', 'gpg_signed.msg');
        $this->assertCount(1, $signed->attachments);
        $this->assertSame('smime.p7s', $signed->attachments[0]->name());
        $this->assertSame('multipart/signed', $signed->attachments[0]->mime());
        $this->assertSame(827, strlen($signed->attachments[0]->data()));
    }

    public function testReadsOxmsgAndMsgKitWriterOutputsWithTheSameSemantics(): void
    {
        foreach ([
            ['testOut_attach.msg', 'msgKitOut_attach.msg'],
            ['testOut_noattach.msg', 'msgKitOut_noattach.msg'],
        ] as [$oxmsgName, $msgKitName]) {
            $oxmsg = $this->fixture('oxmsg', $oxmsgName);
            $msgKit = $this->fixture('oxmsg', $msgKitName);

            $this->assertSame($this->messageSnapshot($msgKit), $this->messageSnapshot($oxmsg), $oxmsgName);
            $this->assertSame('This is the subject', $oxmsg->subject());
            $this->assertSame('peterpan@neverland.com', $oxmsg->senderEmail());
            $this->assertSame(['crocodile@neverland.com'], $oxmsg->to()->pluck('email')->all());
            $this->assertSame('This is a message', $oxmsg->bodyHtml());
        }

        $generated = $this->fixture('oxmsg', 'testOut.msg');
        $withAttachment = $this->fixture('oxmsg', 'testOut_attach.msg');
        $this->assertSame($this->messageSnapshot($withAttachment), $this->messageSnapshot($generated));
        $this->assertSame('d3013cd89c92b7cb11570739b3337dc609bc04f32dea5701f4b5650d404ca102', hash('sha256', $generated->attachments[0]->data()));

        $internal = $this->fixture('oxmsg', 'test_internal.msg');
        $this->assertSame('Re: test internal', $internal->subject());
        $this->assertSame('bedfortest@tutanota.de', $internal->senderEmail());
        $this->assertSame(['arne.moehle@tutao.onmicrosoft.com'], $internal->to()->pluck('email')->all());
        $this->assertStringContainsString('Sicher gesendet mit Tutanota', (string) $internal->body());
        $this->assertSame('serveimage.jpg', $internal->attachments[0]->name());
        $this->assertSame('bb38b5f658b20b488a361c7744b8ef0132b64261e70267864a013db1dabf9d26', hash('sha256', $internal->attachments[0]->data()));
    }

    public function testMatchesMsgParserRsRecipientAndAttachmentExpectations(): void
    {
        $message = $this->fixture('msg-parser-rs', 'test_email.msg');

        $this->assertSame('Test Email', $message->subject());
        $this->assertSame(['marirs@gmail.com'], $message->to()->pluck('email')->all());
        $this->assertSame(['marirs@aol.in', 'marirs@outlook.in'], $message->cc()->pluck('email')->all());
        $this->assertSame(['marirs@aol.in', 'marirs@outlook.com', 'marirs@outlook.in'], $message->bcc()->pluck('email')->all());
        $this->assertSame('IPM.Note', $message->messageClass());
        $this->assertCount(3, $message->attachments);
        $this->assertSame(
            ['1 Days Left—35% off cloud space, upgrade now!', 'milky-way-2695569_960_720.jpg', 'Test Email.msg'],
            array_map(static fn (Attachment $attachment): ?string => $attachment->name(), $message->attachments),
        );
        $this->assertSame(
            [AttachmentMethod::EmbeddedMessage, AttachmentMethod::ByValue, AttachmentMethod::ByValue],
            array_map(static fn (Attachment $attachment): ?AttachmentMethod => $attachment->method(), $message->attachments),
        );
        $this->assertInstanceOf(Message::class, $message->attachments[0]->message());

        $attachmentMessage = $this->fixture('msg-parser-rs', 'attachment.msg');
        $this->assertSame(
            ['loan_proposal.doc', 'image001.png', 'image002.jpg'],
            array_map(static fn (Attachment $attachment): ?string => $attachment->name(), $attachmentMessage->attachments),
        );
        $this->assertSame(
            ['application/msword', 'image/png', 'image/jpeg'],
            array_map(static fn (Attachment $attachment): ?string => $attachment->mime(), $attachmentMessage->attachments),
        );
        $this->assertStringStartsWith("\xd0\xcf\x11\xe0", $attachmentMessage->attachments[0]->data());
        $this->assertStringStartsWith("\x89PNG", $attachmentMessage->attachments[1]->data());
        $this->assertStringStartsWith("\xff\xd8", $attachmentMessage->attachments[2]->data());
    }

    public function testCoversRemainingUniqueMsgParserRsFiles(): void
    {
        $ascii = $this->fixture('msg-parser-rs', 'ascii.msg');
        $this->assertSame('creating an outlook message file', $ascii->subject());
        $this->assertSame('from@domain.com', $ascii->senderEmail());
        $this->assertSame(['to@domain.com'], $ascii->to()->pluck('email')->all());
        $this->assertSame('This message is created by Aspose.Email', $ascii->body());

        $embedded = $this->fixture('msg-parser-rs', 'test_email_1.msg');
        $this->assertCount(3, $embedded->attachments);
        foreach ($embedded->attachments as $attachment) {
            $this->assertSame(AttachmentMethod::EmbeddedMessage, $attachment->method());
            $this->assertInstanceOf(Message::class, $attachment->message());
        }

        $grouped = $this->fixture('msg-parser-rs', 'test_email_2.msg');
        $this->assertCount(1, $grouped->to());
        $this->assertCount(2, $grouped->cc());
        $this->assertCount(2, $grouped->bcc());
        $this->assertCount(3, $grouped->attachments);

        $imageHeavy = $this->fixture('msg-parser-rs', 'test_email_3.msg');
        $this->assertSame('Welcome to your new Outlook.com account', $imageHeavy->subject());
        $this->assertCount(11, $imageHeavy->attachments);
        $this->assertSame('microsoft-logo.png', $imageHeavy->attachments[10]->name());

        $proton = $this->fixture('msg-parser-rs', 'test_email_4.msg');
        $this->assertSame('account-testing7777@protonmail.com', $proton->senderEmail());
        $this->assertStringContainsString('Sent with ProtonMail', (string) $proton->body());
    }

    public function testRejectsTheMsgParserRsMalformedFixture(): void
    {
        $this->expectException(ParseException::class);
        $this->fixture('msg-parser-rs', 'bad_outlook.msg');
    }

    public function testMsgxtractrAnsiDefaultAndUnicodeVariantsAreSemanticallyIdentical(): void
    {
        $messages = array_map(
            fn (string $name): Message => $this->fixture('msgxtractr', $name),
            ['TestMessage-ansi.msg', 'TestMessage-default.msg', 'TestMessage-unicode.msg'],
        );

        foreach ($messages as $message) {
            $this->assertSame($this->messageSnapshot($messages[0]), $this->messageSnapshot($message));
            $this->assertSame('New Message!', $message->subject());
            $this->assertSame('sender@example.com', $message->senderEmail());
            $this->assertSame(['recipient1@example.com', 'recipient2@example.com'], $message->to()->pluck('email')->all());
            $this->assertSame(['cc1@example.com'], $message->cc()->pluck('email')->all());
            $this->assertSame('This is some bold html!', $message->body());
            $this->assertStringContainsString('<b>bold</b>', (string) $message->bodyHtml());
            $this->assertSame('c83ad0fea4b393f7fc49f56eaf000cb72ae70400581aa5c39868e4a339b5c232', hash('sha256', $message->attachments[0]->data()));
        }
    }

    public function testEveryNewValidFixtureRoundTripsByteIdentically(): void
    {
        foreach ([
            'email-outlook-message-perl' => 6,
            'oxmsg'                      => 6,
            'msg-parser-rs'              => 7,
            'msgxtractr'                 => 3,
        ] as $source => $expectedCount) {
            $paths = glob($this->fixturePath($source, '*.msg'));
            $this->assertIsArray($paths);
            sort($paths);

            if ($source === 'msg-parser-rs') {
                $paths = array_values(array_filter($paths, static fn (string $path): bool => basename($path) !== 'bad_outlook.msg'));
            }

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

    /**
     * @return array<string, mixed>
     */
    private function messageSnapshot(Message $message): array
    {
        return [
            'subject'     => $message->subject(),
            'senderName'  => $message->senderName(),
            'senderEmail' => $message->senderEmail(),
            'body'        => $message->body(),
            'bodyHtml'    => $message->bodyHtml(),
            'recipients'  => array_map(static fn (Recipient $recipient): array => [
                $recipient->name(),
                $recipient->email(),
                $recipient->type(),
            ], $message->recipients),
            'attachments' => array_map(static fn (Attachment $attachment): array => [
                $attachment->name(),
                $attachment->mime(),
                $attachment->method(),
                hash('sha256', $attachment->data()),
            ], $message->attachments),
        ];
    }
}
