<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\AttachmentMethod;
use Cosmira\OutlookMessage\Exception\UnsupportedAttachmentMethodException;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\Writer\MessageBuilderFingerprint;
use PHPUnit\Framework\TestCase;

final class AttachmentTest extends TestCase
{
    public function testReplacingAnEmbeddedMessageInvalidatesTheBuilderFingerprint(): void
    {
        $inner = Message::from(Message::make('Inner')->toBinary());
        $builder = Message::make('Outer')->attach(Attachment::fromMessage($inner));
        $before = MessageBuilderFingerprint::make($builder);

        $builder->attachments()[0]->withMessage($inner);

        $this->assertNotSame($before, MessageBuilderFingerprint::make($builder));
    }

    public function testAttachmentMethodMatchesMapiValues(): void
    {
        $expected = [
            'None'               => 0,
            'ByValue'            => 1,
            'ByReference'        => 2,
            'ByReferenceResolve' => 3,
            'ByReferenceOnly'    => 4,
            'EmbeddedMessage'    => 5,
            'Storage'            => 6,
            'ByWebReference'     => 7,
        ];

        $this->assertJsonStringEqualsJsonString(
            (string) json_encode($expected),
            (string) json_encode(array_column(AttachmentMethod::cases(), 'value', 'name')),
        );
    }

    public function testDataAttachmentUsesLaravelStyleFactoriesAndModifiers(): void
    {
        $attachment = Attachment::fromData('content', 'original.txt')
            ->as('renamed.json')
            ->withMime('application/json')
            ->inline('cid:file');

        $this->assertSame('renamed.json', $attachment->name());
        $this->assertSame('.json', $attachment->extension());
        $this->assertSame('application/json', $attachment->mime());
        $this->assertSame('cid:file', $attachment->contentId());
        $this->assertTrue($attachment->isInline());
        $this->assertFalse($attachment->isEmbedded());
        $this->assertSame(AttachmentMethod::ByValue, $attachment->method());
        $this->assertSame('content', $attachment->data());
        $this->assertSame($attachment, $attachment->withData('updated'));
        $this->assertSame('updated', $attachment->data());
    }

    public function testZeroByteAttachmentIsAnEditablePayload(): void
    {
        $attachment = Attachment::fromData('', 'empty.bin');

        $this->assertSame('', $attachment->data());
        $this->assertSame('', $attachment->withData('')->data());
    }

    public function testFromPathReadsDataLazilyAndUsesTheBasename(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'outlook-msg-attachment-');
        $this->assertIsString($path);

        try {
            file_put_contents($path, 'from disk');
            $attachment = Attachment::fromPath($path);
            file_put_contents($path, 'changed before first read');

            $this->assertSame(basename($path), $attachment->name());
            $this->assertSame('changed before first read', $attachment->data());
        } finally {
            @unlink($path);
        }
    }

    public function testFromPathFailsWhenTheAttachmentCannotBeRead(): void
    {
        $attachment = Attachment::fromPath('/missing/outlook-msg-attachment.bin');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read attachment');
        $attachment->data();
    }

    public function testNullNameAndContentKeepAnEmptyUnnamedAttachment(): void
    {
        $attachment = Attachment::fromData('')->as(null);

        $this->assertNull($attachment->name());
        $this->assertSame('', $attachment->data());

        $empty = new Attachment(method: AttachmentMethod::ByValue);
        $this->assertSame('', $empty->data());
    }

    public function testLazyDataIsResolvedOnce(): void
    {
        $resolved = false;
        $attachment = Attachment::fromData(function () use (&$resolved): string {
            throw_if($resolved, \LogicException::class, 'Resolver was called more than once.');

            $resolved = true;

            return 'lazy';
        }, 'lazy.txt');

        $this->assertSame('lazy', $attachment->data());
        $this->assertSame('lazy', $attachment->data());
    }

    public function testLazyResolverMustReturnBinaryString(): void
    {
        $attachment = Attachment::fromData(static fn (): int => 42, 'invalid.bin');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must return a string');
        $attachment->data();
    }

    public function testEmbeddedMessageSupportsBinaryAndSemanticReplacement(): void
    {
        $attachment = Attachment::fromMessage(Message::from(Message::make('Original')->toBinary()), 'nested.msg');

        $this->assertTrue($attachment->isEmbedded());
        $this->assertSame('Original', $attachment->message()?->subject());

        $attachment->withData(Message::make('From binary')->toBinary());
        $this->assertSame('From binary', $attachment->message()->subject());

        $replacement = Message::from(Message::make('From message')->toBinary());
        $this->assertSame($attachment, $attachment->withMessage($replacement));
        $this->assertSame('From message', Message::from($attachment->data())->subject());
    }

    public function testEmbeddedAttachmentWithoutMessageFailsClearly(): void
    {
        $attachment = new Attachment(method: AttachmentMethod::EmbeddedMessage);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has no message payload');
        $attachment->data();
    }

    public function testUnsupportedMethodRejectsPayloadAccess(): void
    {
        $message = Message::from(Message::make('Nested')->toBinary());

        foreach ([
            AttachmentMethod::ByReference,
            AttachmentMethod::ByReferenceResolve,
            AttachmentMethod::ByReferenceOnly,
            AttachmentMethod::Storage,
            AttachmentMethod::ByWebReference,
        ] as $method) {
            foreach ([
                static fn (Attachment $attachment) => $attachment->data(),
                static fn (Attachment $attachment) => $attachment->withData('replacement'),
                static fn (Attachment $attachment) => $attachment->withMessage($message),
            ] as $operation) {
                try {
                    $operation(new Attachment(method: $method));
                    $this->fail(sprintf('%s must reject payload access.', $method->name));
                } catch (UnsupportedAttachmentMethodException $exception) {
                    $this->assertStringContainsString(
                        sprintf('%s (%d)', $method->name, $method->value),
                        $exception->getMessage(),
                    );
                }
            }
        }
    }

    public function testMissingMethodIsDifferentFromNone(): void
    {
        $missing = new Attachment();
        $none = new Attachment(method: AttachmentMethod::None);

        $this->assertNotInstanceOf(AttachmentMethod::class, $missing->method());
        $this->assertSame(AttachmentMethod::None, $none->method());
    }
}
