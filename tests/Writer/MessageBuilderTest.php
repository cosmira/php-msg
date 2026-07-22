<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Writer\AttachmentPayload;
use Cosmira\OutlookMessage\Writer\MessageBuilder;
use Cosmira\OutlookMessage\Writer\RecipientPayload;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MessageBuilderTest extends TestCase
{
    public function testMakeReturnsInstanceWithGivenFields(): void
    {
        $builder = MessageBuilder::make('Subject', 'Sender', 'sender@example.com');

        $this->assertSame('Subject', $builder->subject);
        $this->assertSame('Sender', $builder->senderName);
        $this->assertSame('sender@example.com', $builder->senderEmail);
    }

    public function testMessageMakeAliasReturnsBuilder(): void
    {
        $builder = Message::make('Subject', 'Sender', 'sender@example.com');

        $this->assertSame('Subject', $builder->subject);
        $this->assertSame('Sender', $builder->senderName);
        $this->assertSame('sender@example.com', $builder->senderEmail);
    }

    public function testMakeWithNoArgumentsCreatesEmptyBuilder(): void
    {
        $builder = MessageBuilder::make();

        $this->assertNull($builder->subject);
        $this->assertNull($builder->senderName);
        $this->assertNull($builder->senderEmail);
    }

    public function testRecipientWithStringCreatesPayload(): void
    {
        $builder = new MessageBuilder();
        $result = $builder->recipient('John Doe', 'john@example.com');

        $this->assertSame($builder, $result);
        $recipients = $builder->recipients();
        $this->assertCount(1, $recipients);
        $this->assertSame('John Doe', $recipients[0]->name);
        $this->assertSame('john@example.com', $recipients[0]->email);
    }

    public function testRecipientWithPayloadObjectAddsDirectly(): void
    {
        $payload = new RecipientPayload('Jane', 'jane@example.com', RecipientPayload::CC);
        $builder = new MessageBuilder();
        $builder->recipient($payload);

        $recipients = $builder->recipients();
        $this->assertCount(1, $recipients);
        $this->assertSame($payload, $recipients[0]);
    }

    public function testAttachmentWithStringCreatesPayload(): void
    {
        $builder = new MessageBuilder();
        $result = $builder->attachment('document.pdf', 'PDF content');

        $this->assertSame($builder, $result);
        $attachments = $builder->attachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('document.pdf', $attachments[0]->fileName);
        $this->assertSame('document.pdf', $attachments[0]->displayName);
        $this->assertSame('PDF content', $attachments[0]->content);
    }

    public function testAttachmentWithStringAndNullContentDefaultsToEmpty(): void
    {
        $builder = new MessageBuilder();
        $builder->attachment('empty.txt');

        $attachments = $builder->attachments();
        $this->assertSame('', $attachments[0]->content);
    }

    public function testAttachmentWithPayloadObjectAddsDirectly(): void
    {
        $payload = new AttachmentPayload(fileName: 'file.bin', content: 'data');
        $builder = new MessageBuilder();
        $builder->attachment($payload);

        $this->assertSame($payload, $builder->attachments()[0]);
    }

    public function testDeprecatedAddRecipientDelegatesToRecipient(): void
    {
        $payload = new RecipientPayload('Alice', 'alice@example.com');
        $builder = new MessageBuilder();
        $builder->addRecipient($payload);

        $this->assertCount(1, $builder->recipients());
        $this->assertSame($payload, $builder->recipients()[0]);
    }

    public function testDeprecatedAddAttachmentDelegatesToAttachment(): void
    {
        $payload = new AttachmentPayload(fileName: 'test.txt', content: 'hello');
        $builder = new MessageBuilder();
        $builder->addAttachment($payload);

        $this->assertCount(1, $builder->attachments());
        $this->assertSame($payload, $builder->attachments()[0]);
    }

    public function testFluentBuilderMethodsFillMessageMetadata(): void
    {
        $date = new DateTimeImmutable('2024-01-01 10:00:00');

        $builder = (new MessageBuilder())
            ->from('Taylor', 'taylor@example.com')
            ->subject('Hello')
            ->text('Plain body')
            ->html('<p>HTML body</p>')
            ->rtf('{\\rtf1 Hello}')
            ->withHeaders('X-Test: yes')
            ->sentAt($date);

        $this->assertSame('Taylor', $builder->senderName);
        $this->assertSame('taylor@example.com', $builder->senderEmail);
        $this->assertSame('Hello', $builder->subject);
        $this->assertSame('Plain body', $builder->body);
        $this->assertSame('<p>HTML body</p>', $builder->bodyHtml);
        $this->assertSame('{\\rtf1 Hello}', $builder->bodyRtf);
        $this->assertSame('X-Test: yes', $builder->headers);
        $this->assertSame($date, $builder->date);
    }

    public function testToCcAndBccCreateTypedRecipients(): void
    {
        $builder = (new MessageBuilder())
            ->to('Alice', 'alice@example.com')
            ->cc('Bob', 'bob@example.com')
            ->bcc(RecipientPayload::to('Carol', 'carol@example.com'));

        $recipients = $builder->recipients();

        $this->assertCount(3, $recipients);
        $this->assertSame(RecipientPayload::TO, $recipients[0]->type);
        $this->assertSame(RecipientPayload::CC, $recipients[1]->type);
        $this->assertSame(RecipientPayload::BCC, $recipients[2]->type);
    }

    public function testAttachInlineCreatesInlineAttachment(): void
    {
        $builder = (new MessageBuilder())->attachInline('logo.png', 'image-bytes', 'cid:logo');

        $attachments = $builder->attachments();

        $this->assertCount(1, $attachments);
        $this->assertTrue($attachments[0]->isInline);
        $this->assertSame('cid:logo', $attachments[0]->contentId);
    }

    public function testAttachIsAliasForAttachment(): void
    {
        $builder = (new MessageBuilder())->attach('alias.txt', 'alias body');

        $this->assertSame('alias.txt', $builder->attachments()[0]->fileName);
        $this->assertSame('alias body', $builder->attachments()[0]->content);
    }

    public function testRecipientFactoriesCreateExpectedTypes(): void
    {
        $this->assertSame(RecipientPayload::TO, RecipientPayload::to()->type);
        $this->assertSame(RecipientPayload::CC, RecipientPayload::cc()->type);
        $this->assertSame(RecipientPayload::BCC, RecipientPayload::bcc()->type);
    }

    public function testAttachEmbeddedCreatesEmbeddedAttachment(): void
    {
        $embedded = new MessageBuilder(subject: 'Embedded');
        $builder = (new MessageBuilder())->attachEmbedded($embedded, 'nested.msg');

        $attachments = $builder->attachments();

        $this->assertCount(1, $attachments);
        $this->assertTrue($attachments[0]->isEmbedded());
        $this->assertSame($embedded, $attachments[0]->embedded);
        $this->assertSame('nested.msg', $attachments[0]->fileName);
    }

    public function testWithRawPropertyAddsConvenienceAlias(): void
    {
        $property = new RawProperty('3A0C', 0x001F, 'en', 0);
        $builder = (new MessageBuilder())->withRawProperty($property);

        $this->assertCount(1, $builder->rawProperties());
        $this->assertSame($property, $builder->rawProperties()[0]);
        $this->assertSame([$property], $builder->getRawProperties());
    }

    public function testParsedAttachmentIsCopiedBackToBuilder(): void
    {
        $parsed = Message::parse(
            Message::make('Round trip')
                ->attach('note.txt', 'contents')
                ->toBinary(),
        );

        $attachment = $parsed->toBuilder()->attachments()[0];
        $this->assertSame('note.txt', $attachment->fileName);
        $this->assertSame('contents', $attachment->content);
    }

    public function testToBinaryReturnsMsgBinary(): void
    {
        $binary = MessageBuilder::make()
            ->subject('Binary')
            ->toBinary();

        $parsed = Message::parse($binary);

        $this->assertSame('Binary', $parsed->subject());
    }

    public function testSaveWritesMsgFile(): void
    {
        $path = sys_get_temp_dir().'/php-msg-builder-save-'.bin2hex(random_bytes(8)).'.msg';

        try {
            Message::make()
                ->subject('Saved')
                ->text('Body')
                ->save($path);

            $this->assertFileExists($path);

            $parsed = Message::from((string) file_get_contents($path));
            $this->assertSame('Saved', $parsed->subject());
            $this->assertSame('Body', $parsed->body());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testSaveThrowsWhenTargetCannotBeWritten(): void
    {
        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to write message');
            Message::make()->save('/directory-that-does-not-exist/message.msg');
        } finally {
            restore_error_handler();
        }
    }
}
