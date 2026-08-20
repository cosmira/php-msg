<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\MsgParser;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\Recipient;
use PHPUnit\Framework\TestCase;

final class JotlmsgFixtureTest extends TestCase
{
    public function testMatchesJotlmsgGeneratedMessageOracles(): void
    {
        $base = $this->fixture('generated/base-message.msg');
        $this->assertSame('This is the subject', $base->subject());
        $this->assertSame("Hello,\n\nThis is a simple message.\n\n.Bye.", $base->body());
        $this->assertCount(0, $base->recipients);
        $this->assertCount(0, $base->attachments);

        $withoutAttachment = $this->fixture('generated/without-attachment.msg');
        $this->assertSame('This is a message', $withoutAttachment->subject());
        $this->assertSame('sender@jotlmsg.com', $withoutAttachment->senderEmail());
        $this->assertSame(['cedric@jotlmsg.com', 'ctabin@jotlmsg.com'], $withoutAttachment->to()->pluck('email')->all());
        $this->assertSame(['cc@jotlmsg.com'], $withoutAttachment->cc()->pluck('email')->all());
        $this->assertCount(0, $withoutAttachment->attachments);

        $withAttachments = $this->fixture('generated/with-attachments-2.msg');
        $this->assertCount(3, $withAttachments->to());
        $this->assertCount(3, $withAttachments->cc());
        $this->assertCount(1, $withAttachments->bcc());
        $this->assertSame(
            ['message.txt', 'message2.txt', 'message3.txt'],
            array_map(static fn (Attachment $attachment): ?string => $attachment->name(), $withAttachments->attachments),
        );
        $this->assertSame(['text/plain', 'text/plain', 'text/html'], array_map(
            static fn (Attachment $attachment): ?string => $attachment->mime(),
            $withAttachments->attachments,
        ));
        $this->assertSame('Hello, World!', $withAttachments->attachments[0]->data());
        $this->assertSame('Another attachment with content', $withAttachments->attachments[1]->data());
        $this->assertSame('<html><body>Some html page</body></html>', $withAttachments->attachments[2]->data());
    }

    public function testOurWriterMatchesTheJotlmsgCommonScenarioSemantically(): void
    {
        $upstream = $this->fixture('generated/without-attachment.msg');
        $ours = Message::from(Message::make('This is a message')
            ->from('sender@jotlmsg.com', 'sender@jotlmsg.com')
            ->text("Hello,\n\nThis is a simple message.\n\n.Bye.")
            ->to('Cédric', 'cedric@jotlmsg.com')
            ->to('ctabin@jotlmsg.com')
            ->cc('Copy', 'cc@jotlmsg.com')
            ->toBinary());

        $this->assertSame($this->semanticSnapshot($upstream), $this->semanticSnapshot($ours));
    }

    public function testMatchesJotlmsgHighCardinalityWriterOracles(): void
    {
        $recipients = $this->fixture('generated/many-recipients.msg');
        $this->assertSame('betreff', $recipients->subject());
        $this->assertSame('content', $recipients->body());
        $this->assertCount(40, $recipients->to());
        $this->assertSame('user0@xyz.com', $recipients->recipients[0]->email());
        $this->assertSame('user39@xyz.com', $recipients->recipients[39]->email());

        $attachments = $this->fixture('generated/many-attachments.msg');
        $this->assertCount(40, $attachments->attachments);
        $this->assertSame('test0.txt', $attachments->attachments[0]->name());
        $this->assertSame('this is content 0', $attachments->attachments[0]->data());
        $this->assertSame('test39.txt', $attachments->attachments[39]->name());
        $this->assertSame('this is content 39', $attachments->attachments[39]->data());
        foreach ($attachments->attachments as $attachment) {
            $this->assertSame('text/plain', $attachment->mime());
        }
    }

    public function testMatchesJotlmsgOutlookFixtureOracles(): void
    {
        $simple = $this->fixture('msoutlook/simple.msg');
        $this->assertSame('Test subject', $simple->subject());
        $this->assertStringContainsString('This is a simple test message.', (string) $simple->body());
        $this->assertSame(['to@test.com'], $simple->to()->pluck('email')->all());
        $this->assertSame(['cc@test.com'], $simple->cc()->pluck('email')->all());
        $this->assertSame(['bcc@test.com'], $simple->bcc()->pluck('email')->all());

        $simple2 = $this->fixture('msoutlook/simple2.msg');
        $this->assertSame('My subject', $simple2->subject());
        $this->assertSame("Hello, world.\r\n", $simple2->body());
        $this->assertSame(['roger@test.com'], $simple2->to()->pluck('email')->all());

        $attachmentMessage = $this->fixture('msoutlook/attachment.msg');
        $this->assertSame('', $attachmentMessage->subject());
        $this->assertSame("Mail with attachment and no subject.\r\n", $attachmentMessage->body());
        $this->assertCount(1, $attachmentMessage->attachments);
        $this->assertSame('myAttachement.txt', $attachmentMessage->attachments[0]->name());
        $this->assertSame('text/plain', $attachmentMessage->attachments[0]->mime());
        $this->assertSame('This is some basic content of attached file.', $attachmentMessage->attachments[0]->data());

        $sent = $this->fixture('msoutlook/sent.msg');
        $this->assertSame('2018-02-27T23:00:00+00:00', $sent->date()?->format(DATE_ATOM));
        $this->assertSame('sender@jotlmsg.com', $sent->senderEmail());

        $replyTo = $this->fixture('msoutlook/replyto.msg');
        $this->assertSame("Mail with two reply to recipients.\r\n", $replyTo->body());
        $this->assertSame(['to@test.com'], $replyTo->to()->pluck('email')->all());
    }

    public function testEveryJotlmsgFixtureRoundTripsByteIdentically(): void
    {
        $paths = glob($this->fixturePath('*/*.msg'));
        $this->assertIsArray($paths);
        sort($paths);
        $this->assertCount(10, $paths);

        foreach ($paths as $path) {
            $binary = file_get_contents($path);
            $this->assertIsString($binary, $path);
            $this->assertSame($binary, Message::from($binary)->toBinary(), sprintf('Round-trip changed %s.', $path));
        }
    }

    private function fixture(string $name): Message
    {
        return Message::fromPath($this->fixturePath($name));
    }

    private function fixturePath(string $name): string
    {
        return __DIR__.'/../Fixtures/jotlmsg/'.$name;
    }

    /**
     * @return array<string, mixed>
     */
    private function semanticSnapshot(Message $message): array
    {
        return [
            'subject'     => $message->subject(),
            'senderName'  => $message->senderName(),
            'senderEmail' => $message->senderEmail(),
            'body'        => $message->body(),
            'recipients'  => array_map(static fn (Recipient $recipient): array => [
                $recipient->name(),
                $recipient->email(),
                $recipient->type(),
            ], $message->recipients),
            'attachments' => array_map(static fn (Attachment $attachment): array => [
                $attachment->name(),
                $attachment->mime(),
                hash('sha256', $attachment->data()),
            ], $message->attachments),
        ];
    }
}
