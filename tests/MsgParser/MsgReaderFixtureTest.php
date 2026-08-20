<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\MsgParser;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Message;
use PHPUnit\Framework\TestCase;

final class MsgReaderFixtureTest extends TestCase
{
    public function testEveryUpstreamMsgFixtureParsesAndRoundTripsByteIdentically(): void
    {
        $paths = glob($this->fixturePath('*.msg'));
        $this->assertIsArray($paths);
        sort($paths);

        $this->assertCount(15, $paths);
        foreach ($paths as $path) {
            $binary = file_get_contents($path);
            $this->assertIsString($binary);
            $this->assertSame($binary, Message::from($binary)->toBinary(), basename($path));
        }
    }

    public function testDecodesBodiesAcrossHtmlRtfTextAndRussianSamples(): void
    {
        foreach (['HtmlSampleEmail.msg', 'RtfSampleEmail.msg', 'TxtSampleEmail.msg'] as $name) {
            $message = $this->fixture($name);
            $this->assertStringContainsString('Heavens! what a virulent attack!', (string) $message->body(), $name);
            $this->assertStringContainsString('BOOK ONE: 1805', (string) $message->body(), $name);
        }

        $russian = $this->fixture('RtfWithShortRussianString.msg');
        $this->assertStringContainsString('Имя пользователя', (string) $russian->body());
        $this->assertStringNotContainsString('�', (string) $russian->body());
    }

    public function testPreservesUnicodeSubjectsExactly(): void
    {
        $this->assertSame(
            'Un sujet bien défini Àéroport mañana être ouïe électricité così próxima à Ô',
            $this->fixture('EmailWithSpecialCharsInSubject.msg')->subject(),
        );
        $this->assertSame(
            'Un sujet très bien défini',
            $this->fixture('EmailWithSpecialCharsInSubject_2.msg')->subject(),
        );
    }

    public function testExtractsTheKnownPdfPayloadFromEverySingleAttachmentSample(): void
    {
        foreach (
            [
                'HtmlSampleEmailWithAttachment.msg',
                'RtfSampleEmailWithAttachment.msg',
                'TxtSampleEmailWithAttachment.msg',
            ] as $name
        ) {
            $message = $this->fixture($name);
            $this->assertCount(1, $message->attachments, $name);
            $attachment = $message->attachments[0];
            $this->assertSame('SampleAttachment.pdf', $attachment->name(), $name);
            $this->assertSame('application/pdf', $attachment->mime(), $name);
            $this->assertSame('600f7bed593588956bfc3cfeefec12506120b653', sha1($attachment->data()), $name);
        }
    }

    public function testAttachmentFilenamesInheritTheParentCodepageAndMimeTypes(): void
    {
        $message = $this->fixture('EmailWith2Attachments.msg');
        $this->assertCount(2, $message->attachments);

        $expected = [
            'Installatie handleiding.docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Installatiehandleiding S&I Agro.xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        foreach ($message->attachments as $attachment) {
            $name = $attachment->name();
            $this->assertIsString($name);
            $this->assertArrayHasKey($name, $expected);
            $this->assertStringNotContainsString('�', $name);
            $this->assertSame($expected[$name], $attachment->mime());
        }
    }

    public function testParsesRegularAttachmentsAndRecipientGroups(): void
    {
        $message = $this->fixture('EmailWithAttachments.msg');

        $this->assertSame('This is the subject', $message->subject());
        $this->assertSame('Kees', $message->senderName());
        $this->assertSame('peterpan@neverland.com', $message->senderEmail());
        $this->assertSame(['crocodile@neverland.com'], $message->to()->pluck('email')->all());
        $this->assertSame(['tinkerbel@neverland.com'], $message->cc()->pluck('email')->all());
        $this->assertSame(['wendy@neverland.com'], $message->bcc()->pluck('email')->all());
        $this->assertSame(['peterpan.jpg', 'tinkerbell.jpg'], array_map(
            static fn (Attachment $attachment): ?string => $attachment->name(),
            $message->attachments,
        ));
    }

    public function testParsesNestedMessagesAndAllOuterAndInnerAttachments(): void
    {
        $outer = $this->fixture('EmailWithInnerMailAndAttachments.msg');
        $this->assertSame('Outer mail', $outer->subject());
        $this->assertCount(3, $outer->attachments);
        $this->assertSame(['OUTER 1.pdf', 'OUTER 2.pdf', 'Inner mail'], array_map(
            static fn (Attachment $attachment): ?string => $attachment->name(),
            $outer->attachments,
        ));

        $nested = $outer->attachments[2]->message();
        $this->assertInstanceOf(Message::class, $nested);
        $this->assertSame('Inner mail', $nested->subject());
        $this->assertCount(2, $nested->attachments);
        $this->assertSame(['INNER 1.pdf', 'INNER 2.pdf'], array_map(
            static fn (Attachment $attachment): ?string => $attachment->name(),
            $nested->attachments,
        ));

        $single = $this->fixture('EmailWithMsgAttachment.msg');
        $this->assertCount(1, $single->attachments);
        $this->assertTrue($single->attachments[0]->isEmbedded());
        $this->assertSame('Msg Attachment Test', $single->attachments[0]->message()?->subject());
        $nestedBinary = $single->attachments[0]->data();
        $this->assertSame($nestedBinary, Message::from($nestedBinary)->toBinary());
    }

    public function testUsesAlternateExchangeSmtpSenderAddress(): void
    {
        $message = $this->fixture('sender_not_found_from_exchange.msg');

        $this->assertSame('Myriam VAREME', $message->senderName());
        $this->assertSame('dsi@arcencielrecyclage.fr', $message->senderEmail());
    }

    public function testPreservesReactionPropertiesWithoutTreatingThemAsSenderIdentity(): void
    {
        $message = $this->fixture('EmailWithReactions.msg');

        $this->assertSame('test1@readreceipts.onmicrosoft.com', $message->senderEmail());
        $history = $this->rawProperty($message, '8033');
        $this->assertIsString($history);
        $this->assertStringContainsString('test2@readreceipts.onmicrosoft.com', $history);
        $this->assertStringContainsString('"Type":"celebrate"', $history);
        $this->assertStringContainsString('"Type":"0"', $history);
    }

    private function rawProperty(Message $message, string $id): mixed
    {
        foreach ($message->rawProperties as $property) {
            if (strtolower($property->id) === strtolower($id)) {
                return $property->value;
            }
        }

        return null;
    }

    private function fixture(string $name): Message
    {
        return Message::fromPath($this->fixturePath($name));
    }

    private function fixturePath(string $name): string
    {
        return __DIR__.'/../Fixtures/msg-reader/'.$name;
    }
}
