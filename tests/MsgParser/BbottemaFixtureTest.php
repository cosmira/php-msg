<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\MsgParser;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\AttachmentMethod;
use Cosmira\OutlookMessage\Message;
use PHPUnit\Framework\TestCase;

final class BbottemaFixtureTest extends TestCase
{
    public function testEveryUpstreamFixtureParsesAndRoundTripsByteIdentically(): void
    {
        $paths = glob($this->fixturePath('*.msg'));
        $this->assertIsArray($paths);
        sort($paths);

        $this->assertCount(29, $paths);
        foreach ($paths as $path) {
            $binary = file_get_contents($path);
            $this->assertIsString($binary);
            $this->assertSame($binary, Message::from($binary)->toBinary(), sprintf('Round-trip changed %s.', basename($path)));
        }
    }

    public function testUsesRepresentingAndSmtpSenderPropertiesWithoutLeakingX500Addresses(): void
    {
        $expected = [
            'OutlookMessage with X500 dual address.msg'                    => ['Sven Sielenkemper', 'sielenkemper@otris.de'],
            'tst_unicode.msg'                                              => ['m.kalejs@outlook.com', 'm.kalejs@outlook.com'],
            'HTML mail with replyto and attachment and embedded image.msg' => ['lollypop', 'b.bottema@projectnibble.org'],
            'embedded image.msg'                                           => ['Paliarik, Martin', 'mpaliarik@mdlz.com'],
            'chinese message un_garbled.msg'                               => ['Wang, Zhuo D.', 'zhuo.d.wang@accenture.com'],
            'plain chain.msg'                                              => ['Robert Duncan', null],
            'chinese message.msg'                                          => [null, null],
        ];

        foreach ($expected as $name => [$senderName, $senderEmail]) {
            $message = $this->fixture($name);
            $this->assertSame($senderName, $message->senderName(), $name);
            $this->assertSame($senderEmail, $message->senderEmail(), $name);
        }
    }

    public function testRejectsArbitrarySenderAddressValuesThatAreNotEmails(): void
    {
        $binary = Message::make('Subject', 'Sender', 'Unknown')->toBinary();
        $message = Message::from($binary);

        $this->assertSame('Sender', $message->senderName());
        $this->assertNull($message->senderEmail());
    }

    public function testMatchesUpstreamEnvelopeMetadataForRemainingFixtureScenarios(): void
    {
        $expected = [
            'simple sent.msg' => [
                'John Doe',
                'jdoes@someserver.com',
                '(outlookEMLandMSGconverter Trial Version Import) BitDaddys Software',
            ],
            'simple email with TO and CC_single.msg'   => ['Elias Laugher', 'elias.laugher@gmail.com', 'Test E-Mail'],
            'unsent draft.msg'                         => [null, null, 'MSG Test File'],
            'simple reply with CC.msg'                 => ['Benny Bottema', 'benny@bennybottema.com', 'Re: Sent by CV Contact Form'],
            'Test at sign in personal From header.msg' => ['bogus@acme.com', 'bogus@domain.com', 'Test at sign in personal 3'],
            'attachments.msg'                          => [
                'Microsoft Outlook',
                'MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@coab.us',
                'Delivery delayed:RE: Bosco Fraud Cases [ 2 of 8]',
            ],
            'forward with attachments and embedded images.msg' => [
                null,
                null,
                'FW: Delivery delayed:RE: Bosco Fraud Cases [ 2 of 8]',
            ],
            'S_MIME test message signed.msg'             => ['Benny Bottema', 'benny@bennybottema.com', 'S/MIME test message signed'],
            'S_MIME test message encrypted.msg'          => ['Benny Bottema', 'benny@bennybottema.com', 'S/MIME test message encrypted'],
            'S_MIME test message signed & encrypted.msg' => [
                'Benny Bottema',
                'benny@bennybottema.com',
                'S/MIME test message signed & encrypted',
            ],
        ];

        foreach ($expected as $name => [$senderName, $senderEmail, $subject]) {
            $message = $this->fixture($name);
            $this->assertSame($senderName, $message->senderName(), $name);
            $this->assertSame($senderEmail, $message->senderEmail(), $name);
            $this->assertSame($subject, $message->subject(), $name);
        }
    }

    public function testMatchesUpstreamRecipientAndBodyExpectationsForRemainingFixtureScenarios(): void
    {
        $sent = $this->fixture('simple sent.msg');
        $this->assertSame(['sales@bitdaddys.com'], $sent->to()->pluck('email')->all());
        $this->assertStringContainsString('We have added your software to our approved list.', (string) $sent->body());
        $this->assertNotNull($sent->date());

        $single = $this->fixture('simple email with TO and CC_single.msg');
        $this->assertSame(['sielenkemper@otris.de'], $single->to()->pluck('email')->all());
        $this->assertSame(['niklas.lindson@gmail.com'], $single->cc()->pluck('email')->all());
        $this->assertSame('Just a test to get an email with one cc recipient.', trim((string) $single->body()));

        $draft = $this->fixture('unsent draft.msg');
        $this->assertSame(['time2talk@online-convert.com'], $draft->to()->pluck('email')->all());
        $this->assertStringContainsString('Purpose: Provide example of this file type', (string) $draft->body());
        $this->assertNull($draft->date());

        $reply = $this->fixture('simple reply with CC.msg');
        $this->assertSame(['davidjono555@gmail.com'], $reply->to()->pluck('email')->all());
        $this->assertSame(['b.bottema@projectnibble.org', 'b.bottema@gmail.com'], $reply->cc()->pluck('email')->all());

        $quoted = $this->fixture('Test at sign in personal From header.msg');
        $this->assertSame(['recipient@domain.com'], $quoted->to()->pluck('email')->all());
        $this->assertCount(0, $quoted->attachments);
    }

    public function testClientSubmitTimeWinsOverMessageDeliveryTime(): void
    {
        foreach (['issue-87-client-submit-time.msg', 'issue-87-client-submit-time-corrected.msg'] as $name) {
            $message = $this->fixture($name);

            $this->assertSame('2024-06-04T13:31:19+00:00', $message->date()?->format(DATE_ATOM), $name);
            $this->assertSame('test', $message->subject());
        }
    }

    public function testKeepsRecipientGroupsSeparateWithoutIntroducingDuplicates(): void
    {
        $multiple = $this->fixture('simple email with TO and CC_multiple.msg');

        $this->assertSame(
            ['elias.laugher@gmail.com', 'niklas.lindson@gmail.com'],
            $multiple->to()->pluck('email')->all(),
        );
        $this->assertSame(
            ['egi.champi.titu@gmail.com', 'egi.han.tzu@gmail.com'],
            $multiple->cc()->pluck('email')->all(),
        );
        $this->assertSame(
            ['egi.carn.carby@gmail.com', 'egi.dink.meeker@gmail.com'],
            $multiple->bcc()->pluck('email')->all(),
        );

        foreach (['CC duplicate recipients bug.msg', 'test subject duplicated recipients.msg'] as $name) {
            $message = $this->fixture($name);
            $this->assertCount(1, $message->to(), $name);
            $this->assertCount(1, $message->cc(), $name);
        }
    }

    public function testInfersMissingAttachmentMimeTypeAndPreservesSpecialCharacters(): void
    {
        $message = $this->fixture('attachment with a bracket in the name.msg');
        $this->assertCount(1, $message->attachments);

        $attachment = $message->attachments[0];
        $this->assertSame('Attachment[1.pdf', $attachment->name());
        $this->assertSame('.pdf', $attachment->extension());
        $this->assertSame('application/pdf', $attachment->mime());
        $this->assertSame('dummy attachment', $attachment->data());
    }

    public function testParsesNestedOutlookMessagesAndTheirMetadata(): void
    {
        $message = $this->fixture('nested simple mail.msg');
        $this->assertCount(1, $message->attachments);

        $attachment = $message->attachments[0];
        $nested = $attachment->message();
        $this->assertSame(AttachmentMethod::EmbeddedMessage, $attachment->method());
        $this->assertSame('outlookmsg2html Testmail', $attachment->name());
        $this->assertInstanceOf(Message::class, $nested);
        $this->assertSame('outlookmsg2html Testmail', $nested->subject());
        $this->assertSame('Emanuel.Reisinger@cargonet.software', $nested->senderEmail());
        $this->assertStringContainsString('This is a testmail.', (string) $nested->body());

        $mixed = $this->fixture('testgetmsgAttch.msg');
        $this->assertCount(2, $mixed->attachments);
        $this->assertSame('剑来.jpg', $mixed->attachments[0]->name());
        $this->assertSame('application/octet-stream', $mixed->attachments[0]->mime());
        $nestedMixed = $mixed->attachments[1]->message();
        $this->assertInstanceOf(Message::class, $nestedMixed);
        $this->assertSame('test mail', $nestedMixed->subject());
        $this->assertStringStartsWith('Test12346464', (string) $nestedMixed->body());
    }

    public function testSeparatesInlineContentFromRegularAttachments(): void
    {
        $message = $this->fixture('issue-90-new-outlook-html-inline-image.msg');
        $image = $this->attachmentNamed($message, 'test.png');
        $document = $this->attachmentNamed($message, 'test.pdf');

        $this->assertTrue($image->isInline());
        $this->assertSame('177438a0-381f-49ff-a81f-57404ccdb560', $image->contentId());
        $this->assertSame('31eb75be0197b31051a5433b11e0ca6fa5addcd59bcb96e8b801c48878a436a4', hash('sha256', $image->data()));
        $this->assertFalse($document->isInline());
        $this->assertSame('application/pdf', $document->mime());
        $this->assertStringContainsString('cid:177438a0-381f-49ff-a81f-57404ccdb560', (string) $message->bodyHtml());

        $htmlMessage = $this->fixture('HTML mail with replyto and attachment and embedded image.msg');
        $this->assertTrue($this->attachmentNamed($htmlMessage, 'thumbsup')->isInline());
        $this->assertSame('Black Tie Optional', $this->attachmentNamed($htmlMessage, 'dresscode.txt')->data());
        $this->assertSame('On the moon!', $this->attachmentNamed($htmlMessage, 'location.txt')->data());
    }

    public function testParsesSmimePayloadNamesMimeTypesAndBytes(): void
    {
        $generated = Message::from(
            Message::make()
                ->attach(Attachment::fromData('encrypted')->withMime('application/pkcs7-mime; smime-type=enveloped-data'))
                ->toBinary(),
        );
        $this->assertSame('smime.p7m', $generated->attachments[0]->name());
        $this->assertSame('.p7m', $generated->attachments[0]->extension());

        $expected = [
            'S_MIME test message signed.msg' => [
                'smime.p7s',
                'multipart/signed',
                '4627583e26762c74baa3244ac31b7458328d2fdeea4755814783bfefc0ff0e19',
            ],
            'S_MIME test message encrypted.msg' => [
                'smime.p7m',
                'application/pkcs7-mime',
                'af66ed14f03a41bb63b6acd777794d09219cdef4a6ad22ca587789b5658db133',
            ],
            'S_MIME test message signed & encrypted.msg' => [
                'smime.p7m',
                'application/pkcs7-mime',
                '6941fb9c4818ba9f54462c98894e61685bf13371847928d0f8673b7e65a5c2db',
            ],
        ];

        foreach ($expected as $name => [$fileName, $mimeType, $sha256]) {
            $message = $this->fixture($name);
            $this->assertCount(1, $message->attachments, $name);
            $attachment = $message->attachments[0];
            $this->assertSame($fileName, $attachment->name(), $name);
            $this->assertSame($mimeType, $attachment->mime(), $name);
            $this->assertSame($sha256, hash('sha256', $attachment->data()), $name);
        }
    }

    public function testPreservesUnicodeBodiesSubjectsAndAddressFallbacks(): void
    {
        $unicode = $this->fixture('tst_unicode.msg');
        $this->assertSame('Testcase', $unicode->subject());
        $this->assertSame('doesnotexist@doesnt.com', $unicode->recipients[0]->email());
        foreach (['Char-å-Char', 'Char-Å-Char', 'Char-ø-Char', 'Char-Ø-Char', 'Char-æ-Char', 'Char-Æ-Char'] as $line) {
            $this->assertStringContainsString($line, (string) $unicode->body());
        }

        $chinese = $this->fixture('chinese message.msg');
        $this->assertSame('', $chinese->subject());
        $this->assertStringContainsString('经过汇总 大家提前合理安排进房间入住吧。', (string) $chinese->body());
        $this->assertStringContainsString('酒店研发部', (string) $chinese->body());
        $this->assertStringNotContainsString('�', (string) $chinese->body());

        $modernChinese = $this->fixture('chinese message un_garbled.msg');
        $this->assertSame('测试邮件', $modernChinese->subject());
        $this->assertSame('zhuo.d.wang@accenture.com', $modernChinese->senderEmail());

        $garbled = $this->fixture('chinese message garbled.msg');
        $this->assertStringContainsString('op_CD_jewel_case.mp3', (string) $garbled->body());
        $this->assertStringContainsString('翻牌音效', (string) $garbled->body());
    }

    public function testParsesRtfOnlyBodiesAndAttachmentPayloads(): void
    {
        $plain = $this->fixture('issue-16-rtf-sample-email.msg');
        $this->assertNull($plain->bodyHtml());
        $this->assertStringContainsString('\\rtf1', (string) $plain->bodyRtf());
        $this->assertStringContainsString('BOOK ONE: 1805', (string) $plain->body());
        $this->assertStringContainsString('Well, Prince', (string) $plain->body());

        $withAttachment = $this->fixture('issue-16-rtf-sample-email-with-attachment.msg');
        $this->assertCount(1, $withAttachment->attachments);
        $attachment = $withAttachment->attachments[0];
        $this->assertSame('SampleAttachment.pdf', $attachment->name());
        $this->assertSame('application/pdf', $attachment->mime());
        $this->assertSame('4845736e5fd1fe354b697901eefb9d9c3383ca98f12df5bfadab764fe6d488cd', hash('sha256', $attachment->data()));
    }

    public function testPreservesDeliveryStatusAndForwardedInlinePayloads(): void
    {
        $delivery = $this->fixture('attachments.msg');
        $this->assertCount(2, $delivery->attachments);
        $this->assertSame('message/delivery-status', $delivery->attachments[0]->mime());
        $this->assertSame('4102e6685193098590b5b10e5c44d6a29a113742cdab8b4ed1adf751a37ded54', hash('sha256', $delivery->attachments[0]->data()));
        $this->assertSame('text/rfc822-headers', $delivery->attachments[1]->mime());

        $forward = $this->fixture('forward with attachments and embedded images.msg');
        $this->assertCount(4, $forward->attachments);
        $this->assertCount(2, array_filter($forward->attachments, static fn (Attachment $attachment): bool => $attachment->isInline()));
        $this->assertStringContainsString('cid:image001.png', (string) $forward->bodyRtf());
        $this->assertStringContainsString('cid:image002.png', (string) $forward->bodyRtf());
    }

    private function fixture(string $name): Message
    {
        return Message::fromPath($this->fixturePath($name));
    }

    private function fixturePath(string $name): string
    {
        return __DIR__.'/../Fixtures/outlook-message-parser/'.$name;
    }

    private function attachmentNamed(Message $message, string $name): Attachment
    {
        foreach ($message->attachments as $attachment) {
            if ($attachment->name() === $name) {
                return $attachment;
            }
        }

        throw new \LogicException(sprintf('Attachment %s was not found.', $name));
    }
}
