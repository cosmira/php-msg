<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Writer\MessageBuilder;
use Cosmira\OutlookMessage\Writer\MessageWriter;
use Cosmira\OutlookMessage\Writer\RecipientPayload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SevenZipCompatibilityTest extends TestCase
{
    public function testMinimalMessageIsAValidCompoundFile(): void
    {
        $this->assertValidCompoundFile(
            MessageWriter::make(new MessageBuilder(subject: '7-Zip minimal')),
        );
    }

    public function testMessageWithRecipientAndAttachmentIsAValidCompoundFile(): void
    {
        $message = new MessageBuilder(
            subject: '7-Zip full',
            senderName: 'Sender',
            senderEmail: 'sender@example.com',
            body: 'Body',
            bodyHtml: '<p>Body</p>',
        );
        $message->recipient(new RecipientPayload('Recipient', 'recipient@example.com'));
        $message->attach(Attachment::fromData('attachment', 'attachment.txt'));

        $this->assertValidCompoundFile(MessageWriter::make($message));
    }

    private function assertValidCompoundFile(string $binary): void
    {
        $sevenZip = (new ExecutableFinder())->find('7z');
        if ($sevenZip === null) {
            if (getenv('CI') !== false) {
                $this->fail('7z is required in CI but was not found.');
            }

            $this->markTestSkipped('7z is not installed.');
        }

        $path = tempnam(sys_get_temp_dir(), 'outlook-msg-');
        $this->assertNotFalse($path);

        try {
            $this->assertSame(strlen($binary), file_put_contents($path, $binary));

            $process = new Process([$sevenZip, 't', $path]);
            $process->run();

            $this->assertTrue(
                $process->isSuccessful(),
                trim($process->getOutput().PHP_EOL.$process->getErrorOutput()),
            );
            $this->assertStringContainsString('Everything is Ok', $process->getOutput());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
