<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\MsgParser;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\AttachmentMethod;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\Recipient;
use PHPUnit\Framework\TestCase;

final class UpstreamFixtureTest extends TestCase
{
    public function testParsesMultipleToAndCcRecipientsWithAnEmptyRtfStream(): void
    {
        $message = $this->fixture('multi-to.msg');

        $this->assertSame('Test: multiple To recipients', $message->subject());
        $this->assertSame('Bob', $message->senderName());
        $this->assertSame('bob@example.com', $message->senderEmail());
        $this->assertSame("Test email body.\r\n", $message->body());
        $this->assertNotSame('', $message->bodyHtml());
        $this->assertNull($message->bodyRtf());
        $this->assertSame('', $message->content()->bodyRtfCompressed);

        $this->assertContains('alice@example.com', $message->to()->pluck('email')->all());
        $this->assertContains('carol@example.com', $message->to()->pluck('email')->all());
        $this->assertContains('dave@example.com', $message->cc()->pluck('email')->all());
        $this->assertSame([], $message->bcc()->all());
    }

    public function testPreservesMixedUnicodeTransportHeaders(): void
    {
        $message = $this->fixture('unicode-header.msg');
        $headers = $message->headers();

        $this->assertIsString($headers);
        $this->assertStringContainsString('Alice Smith <alice@example.com>', $headers);
        $this->assertStringContainsString('Bob Plain <bob2@example.com>', $headers);
        $this->assertStringContainsString('=?gb2312?B?6pCzydXCKG1heGNoZW4p?=', $headers);
        $this->assertStringContainsString('maxchen@example.com', $headers);
        $this->assertNull($message->bodyRtf());
    }

    public function testMixedHeaderCasingDoesNotChangeParsedRecipients(): void
    {
        $regular = $this->fixture('multi-to.msg');
        $mixedCase = $this->fixture('multi-to-to.msg');
        $headers = $mixedCase->headers();

        $this->assertIsString($headers);
        $this->assertStringContainsString("TO: Alice Smith <alice@example.com>\r\n", $headers);
        $this->assertStringContainsString("To: Alice Smith <alice@example.com>\r\n", $headers);
        $this->assertSame($this->recipientSnapshot($regular), $this->recipientSnapshot($mixedCase));
        $this->assertSame($regular->subject(), $mixedCase->subject());
        $this->assertSame($regular->body(), $mixedCase->body());
    }

    public function testParsesPlainAndCompressedRtfBodies(): void
    {
        $message = $this->fixture('strange-date.msg');

        $this->assertSame('MSG Test File', $message->subject());
        $this->assertStringStartsWith("MSG test file\r\nPurpose: Provide example", (string) $message->body());
        $this->assertStringStartsWith('{\\rtf1', (string) $message->bodyRtf());
        $this->assertSame('time2talk@online-convert.com', $message->displayTo());
        $this->assertSame('', $message->displayCc());
        $this->assertNull($message->senderEmail());
        $this->assertNull($message->date());
    }

    public function testParsesUnicodeMessageAndBinaryAttachments(): void
    {
        $message = $this->fixture('unicode.msg');

        $this->assertSame('Test for TIF files', $message->subject());
        $this->assertSame('Brian Zhou', $message->senderName());
        $this->assertSame('brizhou@gmail.com', $message->senderEmail());
        $this->assertSame('2013-11-18T08:26:24+00:00', $message->date()?->format(DATE_ATOM));
        $this->assertCount(2, $message->attachments);

        $expected = [
            ['import OleFileIO.tif', '6f36cb718943751db9dc4c9df624c4390d8a13674127b7e919f419061e856dfa'],
            ['raised value error.tif', '3b43fdeca80da38c80e918e8f21cbd6fc925dac994f3922c7893fc6c1326fb92'],
        ];

        foreach ($message->attachments as $index => $attachment) {
            $this->assertSame($expected[$index][0], $attachment->name());
            $this->assertSame('image/tiff', $attachment->mime());
            $this->assertSame(AttachmentMethod::ByValue, $attachment->method());
            $this->assertSame($expected[$index][1], hash('sha256', $attachment->data()));
        }
    }

    public function testParsesUpstreamCanonicalExportsWithTheSameSemantics(): void
    {
        foreach (['strange-date.msg', 'unicode.msg'] as $name) {
            $original = $this->fixture($name);
            $exported = $this->fixture('export-results/'.$name);

            $this->assertSame(
                $this->semanticSnapshot($original),
                $this->semanticSnapshot($exported),
                sprintf('Upstream export changed parsed semantics for %s.', $name),
            );
        }
    }

    public function testOurWriterMatchesUpstreamExportsSemantically(): void
    {
        foreach (['strange-date.msg', 'unicode.msg'] as $name) {
            $original = $this->fixture($name);
            // Cloning deliberately drops the source-binary metadata keyed to the
            // original builder, forcing a fresh serialization instead of the
            // byte-preserving unchanged-message fast path.
            $freshBuilder = clone $original->toBuilder();
            $ours = Message::from($freshBuilder->toBinary());
            $upstream = $this->fixture('export-results/'.$name);

            $this->assertSame(
                $this->semanticSnapshot($upstream),
                $this->semanticSnapshot($ours),
                sprintf('Our writer changed the observable semantics of %s.', $name),
            );
        }
    }

    public function testEveryImportedMsgHasABinaryIdenticalUnchangedRoundTrip(): void
    {
        foreach ([
            'multi-to.msg',
            'multi-to-to.msg',
            'unicode-header.msg',
            'strange-date.msg',
            'unicode.msg',
            'export-results/strange-date.msg',
            'export-results/unicode.msg',
        ] as $name) {
            $path = __DIR__.'/../Fixtures/msg-extractor/'.$name;
            $binary = file_get_contents($path);

            $this->assertIsString($binary);
            $this->assertSame($binary, Message::from($binary)->toBinary(), sprintf('Round-trip changed %s.', $name));
        }
    }

    private function fixture(string $name): Message
    {
        return Message::fromPath(__DIR__.'/../Fixtures/msg-extractor/'.$name);
    }

    /**
     * @return list<array{name:?string,email:?string,type:?int}>
     */
    private function recipientSnapshot(Message $message): array
    {
        return array_values(array_map(static fn (Recipient $recipient): array => [
            'name'  => $recipient->name(),
            'email' => $recipient->email(),
            'type'  => $recipient->type(),
        ], $message->recipients));
    }

    /**
     * @return array<string, mixed>
     */
    private function semanticSnapshot(Message $message): array
    {
        return [
            'date'        => $message->date()?->format(DATE_ATOM),
            'subject'     => $message->subject(),
            'senderName'  => $message->senderName(),
            'senderEmail' => $message->senderEmail(),
            'body'        => $message->body(),
            'bodyHtml'    => $message->bodyHtml(),
            'bodyRtf'     => $message->bodyRtf(),
            'headers'     => $message->headers(),
            'recipients'  => $this->recipientSnapshot($message),
            'attachments' => array_map(static fn (Attachment $attachment): array => [
                'name'     => $attachment->name(),
                'mime'     => $attachment->mime(),
                'method'   => $attachment->method()?->value,
                'content'  => hash('sha256', $attachment->data()),
            ], $message->attachments),
        ];
    }
}
