<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\MessageEditorFormat;
use Cosmira\OutlookMessage\MessageImportance;
use Cosmira\OutlookMessage\MessagePriority;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MsgKitCompatibilityTest extends TestCase
{
    public function testBuildsTheCommonMsgKitEmailScenarioThroughMake(): void
    {
        $sentAt = new DateTimeImmutable('2026-06-03T12:00:00+00:00');
        $receivedAt = new DateTimeImmutable('2026-06-03T12:01:00+00:00');
        $nested = Message::from(Message::make('Nested message')->text('Nested body')->toBinary());

        $binary = Message::make('Hello Neverland subject')
            ->from('Peter Pan', 'peterpan@neverland.com')
            ->representedBy('Tinkerbell', 'tinkerbell@neverland.com')
            ->subject('RE: This is the subject')
            ->text('Hello Neverland text')
            ->html('<html><body><b>Hello Neverland html</b></body></html>')
            ->rtf('{\rtf1 Hello Neverland RTF}')
            ->sentAt($sentAt)
            ->receivedAt($receivedAt)
            ->importance(MessageImportance::High)
            ->priority(MessagePriority::Urgent)
            ->draft(false)
            ->requestReadReceipt()
            ->iconIndex(0x00000100)
            ->editorFormat(MessageEditorFormat::Html)
            ->messageId('<message@neverland.com>')
            ->references('<first@neverland.com> <parent@neverland.com>')
            ->inReplyTo('<parent@neverland.com>')
            ->to('Captain Hook', 'captainhook@neverland.com')
            ->cc('The evil ticking crocodile', 'crocodile@neverland.com')
            ->bcc('Wendy', 'wendy@neverland.com')
            ->attachPath($this->fixturePath('peterpan.jpg'), 'image/jpeg')
            ->inlineData(
                (string) file_get_contents($this->fixturePath('tinkerbell.jpg')),
                'tinkerbell.jpg',
                'tinkerbell.jpg',
                'image/jpeg',
            )
            ->attachMessage($nested, 'nested.msg')
            ->toBinary();

        $message = Message::from($binary);

        $this->assertSame('Tinkerbell', $message->senderName());
        $this->assertSame('tinkerbell@neverland.com', $message->senderEmail());
        $this->assertSame('Peter Pan', $message->actualSenderName());
        $this->assertSame('peterpan@neverland.com', $message->actualSenderEmail());
        $this->assertSame('Tinkerbell', $message->representingName());
        $this->assertSame('tinkerbell@neverland.com', $message->representingEmail());
        $this->assertSame($sentAt->format(DATE_ATOM), $message->date()?->format(DATE_ATOM));
        $this->assertSame($receivedAt->format(DATE_ATOM), $message->receivedAt()?->format(DATE_ATOM));
        $this->assertSame(MessageImportance::High, $message->importance());
        $this->assertSame(MessagePriority::Urgent, $message->priority());
        $this->assertFalse($message->isDraft());
        $this->assertTrue($message->readReceiptRequested());
        $this->assertSame(0x00000100, $message->iconIndex());
        $this->assertSame(MessageEditorFormat::Html, $message->editorFormat());
        $this->assertSame('<message@neverland.com>', $message->internetMessageId());
        $this->assertSame('<first@neverland.com> <parent@neverland.com>', $message->internetReferences());
        $this->assertSame('<parent@neverland.com>', $message->inReplyToId());
        $this->assertSame(['captainhook@neverland.com'], $message->to()->pluck('email')->all());
        $this->assertSame(['crocodile@neverland.com'], $message->cc()->pluck('email')->all());
        $this->assertSame(['wendy@neverland.com'], $message->bcc()->pluck('email')->all());
        $this->assertCount(3, $message->attachments);
        $this->assertSame('peterpan.jpg', $message->attachments[0]->name());
        $this->assertFalse($message->attachments[0]->isInline());
        $this->assertSame('tinkerbell.jpg', $message->attachments[1]->contentId());
        $this->assertTrue($message->attachments[1]->isInline());
        $this->assertSame('Nested message', $message->attachments[2]->message()?->subject());
    }

    public function testMakeDefaultsToTheExistingDraftMetadata(): void
    {
        $message = Message::from(Message::make('Draft')->toBinary());

        $this->assertTrue($message->isDraft());
        $this->assertSame(MessageImportance::Normal, $message->importance());
        $this->assertSame(MessagePriority::NonUrgent, $message->priority());
        $this->assertSame(0x00000103, $message->iconIndex());
        $this->assertFalse($message->readReceiptRequested());
    }

    public function testConvenienceAttachmentMethodsAndCollectionRemovalAreFluent(): void
    {
        $first = Attachment::fromData('first', 'first.txt');
        $builder = Message::make('Collections')
            ->to('Alice', 'alice@example.com')
            ->cc('Bob', 'bob@example.com')
            ->attach($first)
            ->attachData('second', 'second.txt', 'text/plain');

        $this->assertSame($builder, $builder->detach($first));
        $this->assertSame(['second.txt'], array_map(
            static fn (Attachment $attachment): ?string => $attachment->name(),
            $builder->attachments(),
        ));

        $messageWithoutRecipients = Message::from($builder->withoutRecipients()->toBinary());
        $this->assertCount(0, $messageWithoutRecipients->recipients);
        $this->assertCount(1, $messageWithoutRecipients->attachments);

        $messageWithoutAttachments = Message::from($builder->withoutAttachments()->toBinary());
        $this->assertCount(0, $messageWithoutAttachments->attachments);
    }

    public function testParsedMessagesKeepNewMetadataWhenEditedThroughToBuilder(): void
    {
        $original = Message::from(Message::make('Metadata')
            ->from('Sender', 'sender@example.com')
            ->representedBy('Delegate', 'delegate@example.com')
            ->importance(MessageImportance::Low)
            ->priority(MessagePriority::Normal)
            ->draft(false)
            ->requestReadReceipt()
            ->editorFormat(MessageEditorFormat::PlainText)
            ->messageId('<metadata@example.com>')
            ->toBinary());

        $edited = Message::from($original->toBuilder()->subject('Edited')->toBinary());

        $this->assertSame('Edited', $edited->subject());
        $this->assertSame('Sender', $edited->actualSenderName());
        $this->assertSame('Delegate', $edited->representingName());
        $this->assertSame(MessageImportance::Low, $edited->importance());
        $this->assertSame(MessagePriority::Normal, $edited->priority());
        $this->assertFalse($edited->isDraft());
        $this->assertTrue($edited->readReceiptRequested());
        $this->assertSame(MessageEditorFormat::PlainText, $edited->editorFormat());
        $this->assertSame('<metadata@example.com>', $edited->internetMessageId());
    }

    private function fixturePath(string $name): string
    {
        return __DIR__.'/../Fixtures/msg-kit/'.$name;
    }
}
