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

        $this->assertInstanceOf(\MsgViewer\RawProperty::class, $found, 'Custom raw property 0x6700 should survive round-trip');
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

        $this->assertInstanceOf(\MsgViewer\RawProperty::class, $found, 'Raw property must survive double round-trip');
        $this->assertEquals(99, $found->value);
    }

    public function testGetRawPropertiesMethodExists(): void
    {
        $binary = MessageWriter::make(MessageBuilder::make('test'));
        $msg = MessageParser::parse($binary);

        $this->assertNotEmpty($msg->getRawProperties());
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

        $this->assertInstanceOf(\MsgViewer\RawProperty::class, $found, 'Recipient raw property must survive round-trip');
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

        $this->assertInstanceOf(\MsgViewer\RawProperty::class, $found, 'Attachment raw property must survive round-trip');
        $this->assertEquals(55, $found->value);
    }

    public function testBooleanPropertyTriggersSizeOneParsePath(): void
    {
        // PtypBoolean (typeId=0x000B, size=1) hits PropertyStreamReader line 61
        $boolProp = new RawProperty('9A00', 0x000B, 1, 0);
        $builder = MessageBuilder::make('Boolean Prop')->rawProperty($boolProp);

        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        $found = null;
        foreach ($parsed->getRawProperties() as $p) {
            if ($p->id === '9a00') {
                $found = $p;
                break;
            }
        }

        $this->assertInstanceOf(RawProperty::class, $found);
        $this->assertSame(0x000B, $found->typeId);
    }

    public function testInteger16PropertyTriggersSizeTwoParsePath(): void
    {
        // PtypInteger16 (typeId=0x0002, size=2) hits PropertyStreamReader line 63
        $int16Prop = new RawProperty('9B00', 0x0002, 42, 0);
        $builder = MessageBuilder::make('Int16 Prop')->rawProperty($int16Prop);

        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        $found = null;
        foreach ($parsed->getRawProperties() as $p) {
            if ($p->id === '9b00') {
                $found = $p;
                break;
            }
        }

        $this->assertInstanceOf(RawProperty::class, $found);
        $this->assertSame(0x0002, $found->typeId);
    }

    public function testString8PropertyInRawValueReturnsNull(): void
    {
        // PtypString8 (typeId=0x001E) in rawValue with no stream returns null → not added
        // Hits MessageParser::rawValue line 216 (no stream → null)
        $str8Prop = new RawProperty('9C00', 0x001E, 'hello', 0);
        $builder = MessageBuilder::make('String8 Raw Prop')->rawProperty($str8Prop);

        $binary = MessageWriter::make($builder);
        $parsed = MessageParser::parse($binary);

        // PtypString8 variable-size without stream → rawValue returns null → not in rawProperties
        $found = array_filter($parsed->getRawProperties(), fn (\MsgViewer\RawProperty $p) => $p->id === '9c00');
        $this->assertEmpty($found);
    }

    public function testBinaryPropertyInRawValueReturnsRawBytes(): void
    {
        // PtypBinary (typeId=0x0102) with stream hits rawValue line 259 ($raw returned)
        // Build CFB manually to include both the property entry and the binary stream
        $builder = new \MsgViewer\Writer\CompoundBuilder();
        $root = $builder->rootIndex();

        $propEntry = pack('V', (0x9D00 << 16) | 0x0102) // tag: ID=9D00, type=Binary
            .pack('V', 0)                                // flags
            .pack('V', 3)                                // size=3
            .pack('V', 0);                               // reserved

        $propStream = str_repeat("\0", 8)
            .pack('V', 0).pack('V', 0).pack('V', 0).pack('V', 0)
            .str_repeat("\0", 8)
            .$propEntry;

        $builder->addStream('__properties_version1.0', $propStream, $root);
        $builder->addStream('__substg1.0_9d000102', "\x01\x02\x03", $root);

        $parsed = \MsgViewer\MessageParser::parse($builder->build());

        $found = null;
        foreach ($parsed->getRawProperties() as $p) {
            if ($p->id === '9d00') {
                $found = $p;
                break;
            }
        }

        $this->assertInstanceOf(RawProperty::class, $found);
        $this->assertSame("\x01\x02\x03", $found->value);
    }

    public function testMultiValuePropertyInRawValueHitsDefaultPath(): void
    {
        // PtypMultipleInteger32 (typeId=0x1003, multi=true) with stream → default $raw (line 261)
        $builder = new \MsgViewer\Writer\CompoundBuilder();
        $root = $builder->rootIndex();

        $propEntry = pack('V', (0x9E00 << 16) | 0x1003) // tag: ID=9E00, type=MultiInteger32
            .pack('V', 0)
            .pack('V', 8)  // size=8 (two int32 values)
            .pack('V', 0);

        $propStream = str_repeat("\0", 8)
            .pack('V', 0).pack('V', 0).pack('V', 0).pack('V', 0)
            .str_repeat("\0", 8)
            .$propEntry;

        $builder->addStream('__properties_version1.0', $propStream, $root);
        $builder->addStream('__substg1.0_9e001003', pack('VV', 10, 20), $root);

        $parsed = \MsgViewer\MessageParser::parse($builder->build());

        $found = null;
        foreach ($parsed->getRawProperties() as $p) {
            if ($p->id === '9e00') {
                $found = $p;
                break;
            }
        }

        $this->assertInstanceOf(RawProperty::class, $found);
        $this->assertSame(0x1003, $found->typeId);
    }

    public function testString8PropertyWithStreamReturnsNullInRawValue(): void
    {
        // PtypString8 (typeId=0x001E) with stream present → rawValue returns null (line 252)
        $builder = new \MsgViewer\Writer\CompoundBuilder();
        $root = $builder->rootIndex();

        $propEntry = pack('V', (0x9F10 << 16) | 0x001E)
            .pack('V', 0)
            .pack('V', 5)
            .pack('V', 0);

        $propStream = str_repeat("\0", 8)
            .pack('V', 0).pack('V', 0).pack('V', 0).pack('V', 0)
            .str_repeat("\0", 8)
            .$propEntry;

        $builder->addStream('__properties_version1.0', $propStream, $root);
        $builder->addStream('__substg1.0_9f10001e', 'hello', $root);

        $parsed = \MsgViewer\MessageParser::parse($builder->build());

        // String8 with stream → null returned by rawValue → skipped from rawProperties
        $found = array_filter($parsed->getRawProperties(), fn (\MsgViewer\RawProperty $p) => $p->id === '9f10');
        $this->assertEmpty($found);
    }

    public function testObjectPropertyWithStreamReturnsRawInRawValue(): void
    {
        // PtypObject (typeId=0x000D) with stream → rawValue returns $raw (line 260)
        $builder = new \MsgViewer\Writer\CompoundBuilder();
        $root = $builder->rootIndex();

        $propEntry = pack('V', (0x9F20 << 16) | 0x000D)
            .pack('V', 0)
            .pack('V', 4)
            .pack('V', 0);

        $propStream = str_repeat("\0", 8)
            .pack('V', 0).pack('V', 0).pack('V', 0).pack('V', 0)
            .str_repeat("\0", 8)
            .$propEntry;

        $builder->addStream('__properties_version1.0', $propStream, $root);
        $builder->addStream('__substg1.0_9f20000d', pack('V', 0xDEAD), $root);

        $parsed = \MsgViewer\MessageParser::parse($builder->build());

        $found = null;
        foreach ($parsed->getRawProperties() as $p) {
            if ($p->id === '9f20') {
                $found = $p;
                break;
            }
        }

        $this->assertInstanceOf(RawProperty::class, $found);
        $this->assertSame(0x000D, $found->typeId);
    }
}
