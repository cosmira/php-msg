<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Writer;

use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Writer\AttachmentPayload;
use Cosmira\OutlookMessage\Writer\MapiStorageEncoder;
use Cosmira\OutlookMessage\Writer\MessageBuilder;
use Cosmira\OutlookMessage\Writer\RecipientPayload;
use Cosmira\OutlookMessage\Writer\StorageStreams;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MapiStorageEncoderSnapshotTest extends TestCase
{
    public function testCanonicalStorageSnapshots(): void
    {
        $message = new MessageBuilder(
            subject: 'RE: Topic',
            senderName: 'Sender',
            senderEmail: 's@example.com',
            body: 'Body',
            bodyHtml: '<b>Body</b>',
            bodyRtf: '{\\rtf1 Body}',
            headers: 'X-Test: yes',
            date: new DateTimeImmutable('2024-01-02T03:04:05+00:00'),
        );
        $message->to('A', 'a@example.com')->attach('x.txt', 'x');

        $this->assertStorage(
            MapiStorageEncoder::forMessage($message, 123),
            32,
            '4ddce6a8a3ed09176046e928bf624c8e90856ea9c5fa02d4df7c944d0ada72b8',
            '7235827b24f5a8c22104a887b4bd3b804fb9a0723aa33c367000cc74bf7de286',
        );
        $this->assertStorage(
            MapiStorageEncoder::forRecipient(new RecipientPayload('Alice', 'a@example.com', 2), 7),
            8,
            'fdfcffaad4ccf158c4f0e7779155d3b35eb7ab5c5855ee16914469ac1b96ab40',
            '50b33dd7c79607ba940336af8fa2f86f2008015bb52be43cc9f8186e0433f544',
        );
        $this->assertStorage(
            MapiStorageEncoder::forAttachment(new AttachmentPayload(
                fileName: 'x.txt', displayName: 'X', mimeType: 'text/plain', language: 'en',
                extension: '.txt', content: 'abc', contentId: 'cid', isInline: true,
            ), 3),
            8,
            'ff3b87eea18d527cdf98dd6aec7bff009969ab8ef83760d6e54eeb3eb6a5b259',
            '799777704f6e18fb4050976905246792eaf1e719370b28cc67845e25d09fd136',
        );
        $this->assertStorage(
            MapiStorageEncoder::forEmbeddedAttachment(
                AttachmentPayload::embedded(new MessageBuilder(subject: 'Inner'), 'inner.msg'),
                4,
            ),
            8,
            '56dea1c5e531467f9062c8aa022b745d2a608c8b2ae8a7fe6cbe62e339dcae43',
            'f4784461bc4e43bd45b188847e125322614bf1c412652ff4a0175d7619bf6c33',
        );
    }

    private function assertStorage(StorageStreams $storage, int $headerSize, string $propertiesHash, string $streamsHash): void
    {
        $properties = $storage->properties;
        for ($offset = $headerSize; $offset + 16 <= strlen($properties); $offset += 16) {
            $tag = (new BinaryBuffer($properties))->getUint32($offset);
            if (($tag >> 16) === 0x3007 || ($tag >> 16) === 0x3008) {
                $properties = substr_replace($properties, str_repeat("\0", 8), $offset + 8, 8);
            }
        }

        $streams = $storage->streams;
        foreach ($streams as $name => $value) {
            $lower = strtolower($name);
            if (str_contains($lower, '0ff6') || str_contains($lower, '0ff9') || str_contains($lower, '300b')) {
                $streams[$name] = str_repeat("\0", strlen($value));
            }
        }
        ksort($streams);

        $this->assertSame($propertiesHash, hash('sha256', $properties));
        $this->assertSame($streamsHash, hash('sha256', serialize($streams)));
    }
}
