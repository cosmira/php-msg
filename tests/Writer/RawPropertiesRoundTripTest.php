<?php

declare(strict_types=1);

namespace MsgViewer\Tests\Writer;

use MsgViewer\MessageParser;
use MsgViewer\RawProperty;
use MsgViewer\Writer\AttachmentPayload;
use MsgViewer\Writer\MessageBuilder;
use MsgViewer\Writer\MessageWriter;
use MsgViewer\Writer\RecipientPayload;
use PHPUnit\Framework\TestCase;

final class RawPropertiesRoundTripTest extends TestCase
{
    public function testUnknownMessagePropertySurvivesRoundTrip(): void
    {
        // PR_SENSITIVITY (0x0036, Integer32) — a known MAPI prop we treat as "raw"
        // Use a truly obscure ID unlikely to be in known definitions: 0x6700, Integer32
        $customProp = new RawProperty('6700', 0x0003, 42, 0);

        $builder = MessageBuilder::make('Round-trip subject')
            ->rawProperty($customProp);

        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        $rawProps = $parsed->getRawProperties();

        $found = null;
        foreach ($rawProps as $p) {
            if ($p->id === '6700') {
                $found = $p;
                break;
            }
        }

        $this->assertNotNull($found, 'Custom raw property 0x6700 should survive round-trip');
        $this->assertSame(0x0003, $found->typeId, 'Type ID must be preserved');
        $this->assertEquals(42, $found->value, 'Value must be preserved');
    }

    public function testDoubleRoundTripPreservesRawProperty(): void
    {
        $customProp = new RawProperty('6701', 0x0003, 99, 0);

        $builder = MessageBuilder::make('Double round-trip')
            ->rawProperty($customProp);

        // First round-trip
        $binary1 = MessageWriter::make($builder);
        $parsed1 = MessageParser::parse($binary1);

        // Rebuild using parsed raw properties
        $builder2 = MessageBuilder::make($parsed1->content->subject);
        foreach ($parsed1->getRawProperties() as $raw) {
            $builder2->rawProperty($raw);
        }

        // Second round-trip
        $binary2 = MessageWriter::make($builder2);
        $parsed2 = MessageParser::parse($binary2);

        $found = null;
        foreach ($parsed2->getRawProperties() as $p) {
            if ($p->id === '6701') {
                $found = $p;
                break;
            }
        }

        $this->assertNotNull($found, 'Raw property must survive double round-trip');
        $this->assertEquals(99, $found->value);
    }

    public function testGetRawPropertiesMethodExists(): void
    {
        $binary = MessageWriter::make(MessageBuilder::make('test'));
        $msg = MessageParser::parse($binary);

        $this->assertIsArray($msg->getRawProperties());
    }

    public function testRecipientRawPropertySurvivesRoundTrip(): void
    {
        $customProp = new RawProperty('6800', 0x0003, 77, 0);

        $recipient = new RecipientPayload('Alice', 'alice@example.com', RecipientPayload::TO, [$customProp]);
        $builder = MessageBuilder::make('Recipient raw props')->recipient($recipient);

        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        $this->assertCount(1, $parsed->recipients);
        $rawProps = $parsed->recipients[0]->getRawProperties();

        $found = null;
        foreach ($rawProps as $p) {
            if ($p->id === '6800') {
                $found = $p;
                break;
            }
        }

        $this->assertNotNull($found, 'Recipient raw property must survive round-trip');
        $this->assertEquals(77, $found->value);
    }

    public function testAttachmentRawPropertySurvivesRoundTrip(): void
    {
        $customProp = new RawProperty('6900', 0x0003, 55, 0);

        $attachment = new AttachmentPayload(
            fileName: 'test.txt',
            content: 'hello',
            rawProperties: [$customProp],
        );
        $builder = MessageBuilder::make('Attachment raw props')->attachment($attachment);

        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        $this->assertCount(1, $parsed->attachments);
        $rawProps = $parsed->attachments[0]->getRawProperties();

        $found = null;
        foreach ($rawProps as $p) {
            if ($p->id === '6900') {
                $found = $p;
                break;
            }
        }

        $this->assertNotNull($found, 'Attachment raw property must survive round-trip');
        $this->assertEquals(55, $found->value);
    }
}
