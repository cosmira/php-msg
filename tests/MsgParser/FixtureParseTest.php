<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\MsgParser;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\CompoundFile\CompoundFile;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\Mapi\PropertyData;
use Cosmira\OutlookMessage\Mapi\PropertyStreamReader;
use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Rtf\RtfDecompressor;
use Cosmira\OutlookMessage\Support\BinaryBuffer;
use PHPUnit\Framework\TestCase;

final class FixtureParseTest extends TestCase
{
    public function testReadsGrifyFixtureProperties(): void
    {
        $binary = file_get_contents(__DIR__ . '/../Fixtures/simple-example.msg');
        $this->assertNotFalse($binary);

        $message = Message::parse($binary);

        $this->assertSame('Грифы', $message->content->subject);
        $this->assertSame("Совершенно секретно\r\n", $message->content->body);
        $this->assertSame('test@test.ru', $message->content->to);
        $this->assertSame('', $message->content->cc);
        $this->assertSame('', $message->content->bcc);
        $this->assertIsString($message->content->bodyRtf);
        $this->assertStringStartsWith("{\\rtf1", $message->content->bodyRtf);
        $this->assertCount(1, $message->recipients);
        $this->assertSame('test@test.ru', $message->recipients[0]->name);
        $this->assertSame('test@test.ru', $message->recipients[0]->email);

        $compound = CompoundFile::fromBinary(new BinaryBuffer($binary));
        $root = $compound->directory->entries[0];
        $propertyStream = PropertyStreamReader::forFolder($compound, $root, true);

        $this->assertNotNull($propertyStream);

        $this->assertProperty($propertyStream->data, '3007', 0x0040, 0x00000002, '134206248439540000');
        $this->assertProperty($propertyStream->data, '3008', 0x0040, 0x00000002, '134206248439540000');
        $this->assertProperty($propertyStream->data, '0FF7', 0x0003, 0x00000002, 0);
        $this->assertProperty($propertyStream->data, '0FF4', 0x0003, 0x00000002, 2);
        $this->assertProperty($propertyStream->data, '340D', 0x0003, 0x00000002, 265849);
        $this->assertProperty($propertyStream->data, '0002', 0x000B, 0x00000006, 1);
        $this->assertProperty($propertyStream->data, '0017', 0x0003, 0x00000006, 1);
        $this->assertProperty($propertyStream->data, '001A', 0x001F, 0x00000006, 18);
        $this->assertProperty($propertyStream->data, '0023', 0x000B, 0x00000006, 0);
        $this->assertProperty($propertyStream->data, '0026', 0x0003, 0x00000006, 0);
        $this->assertProperty($propertyStream->data, '0029', 0x000B, 0x00000006, 0);
        $this->assertProperty($propertyStream->data, '0036', 0x0003, 0x00000006, 0);
        $this->assertProperty($propertyStream->data, '0037', 0x001F, 0x00000006, 12);
        $this->assertProperty($propertyStream->data, '0070', 0x001F, 0x00000006, 12);
        $this->assertProperty($propertyStream->data, '0071', 0x0102, 0x00000006, 22);
        $this->assertProperty($propertyStream->data, '0E01', 0x000B, 0x00000006, 0);
        $this->assertProperty($propertyStream->data, '0E07', 0x0003, 0x00000006, 8);
        $this->assertProperty($propertyStream->data, '1080', 0x0003, 0x00000006, 4294967295);
        $this->assertProperty($propertyStream->data, '300B', 0x0102, 0x00000006, 16);
        $this->assertProperty($propertyStream->data, '3FDE', 0x0003, 0x00000006, 65001);
        $this->assertProperty($propertyStream->data, '3FF1', 0x0003, 0x00000006, 1049);
        $this->assertProperty($propertyStream->data, '003D', 0x001F, 0x00000006, 2);
        $this->assertProperty($propertyStream->data, '0E1F', 0x000B, 0x00000006, 1);
        $this->assertProperty($propertyStream->data, '1000', 0x001F, 0x00000006, 44);
        $this->assertProperty($propertyStream->data, '1009', 0x0102, 0x00000006, 8242);
        $this->assertProperty($propertyStream->data, '3FFA', 0x001F, 0x00000006, 54);
        $this->assertProperty($propertyStream->data, '0E1D', 0x001F, 0x00000002, 12);
        $this->assertProperty($propertyStream->data, '0E04', 0x001F, 0x00000002, 28);
        $this->assertProperty($propertyStream->data, '0E03', 0x001F, 0x00000002, 2);
        $this->assertProperty($propertyStream->data, '0E02', 0x001F, 0x00000002, 2);

        $this->assertSame('Грифы', $this->readUtf16Stream($compound, $root->childId, '0037'));
        $this->assertSame('Грифы', $this->readUtf16Stream($compound, $root->childId, '0070'));
        $this->assertSame('', $this->readUtf16Stream($compound, $root->childId, '003D'));
        $this->assertSame('Грифы', $this->readUtf16Stream($compound, $root->childId, '0E1D'));
        $this->assertSame('test@test.ru', $this->readUtf16Stream($compound, $root->childId, '0E04'));
        $this->assertSame('', $this->readUtf16Stream($compound, $root->childId, '0E03'));
        $this->assertSame('', $this->readUtf16Stream($compound, $root->childId, '0E02'));
        $this->assertSame('IPM.Note', $this->readUtf16Stream($compound, $root->childId, '001A'));
        $this->assertSame('Anar.Mamedov@infowatch.com', $this->readUtf16Stream($compound, $root->childId, '3FFA'));
        $this->assertSame("Совершенно секретно\r\n", $this->readUtf16Stream($compound, $root->childId, '1000'));
        $this->assertSame(
            '01dccbdf2f96d06c1de6102a4331a6c70014aad3cc0e',
            bin2hex($this->readBinaryStream($compound, $root->childId, '0071'))
        );
        $this->assertSame(
            '3a57f21d0e7f6b409d0909b55d413761',
            bin2hex($this->readBinaryStream($compound, $root->childId, '300B'))
        );
        $rawRtf = $this->readBinaryStream($compound, $root->childId, '1009');
        $this->assertSame(8242, strlen($rawRtf));
        $this->assertSame(RtfDecompressor::decompress($rawRtf), $message->content->bodyRtf);

        $raw = $this->rawPropertyMap($message->getRawProperties());

        $this->assertRawString($raw, '8000', 'testing@test.ru');
        $this->assertRawString($raw, '8001', "00000009\u{0001}anar.mamedov@infowatch.com");
        $this->assertRawInt($raw, '8002', 0x0003, 0);
        $this->assertRawInt($raw, '8003', 0x0003, 165483);
        $this->assertRawInt($raw, '8004', 0x000B, 0);
        $this->assertRawInt($raw, '8005', 0x0003, 0);
        $this->assertRawInt($raw, '8006', 0x000B, 0);
        $this->assertRawString($raw, '8007', '16.0');
        $this->assertRawInt($raw, '8008', 0x000B, 0);
        $this->assertRawInt($raw, '8009', 0x0003, 0);
        $this->assertRawInt($raw, '800A', 0x0003, 1049);
        $this->assertRawBinary($raw, '800B', 3175, '504b0304');
        $this->assertRawBinary($raw, '800C', 314, '3c3f786d6c2076657273696f6e3d');
    }

    private function parseFixture(string $fixture): Message
    {
        $binary = file_get_contents($this->fixturePath($fixture));
        $this->assertNotFalse($binary);

        return Message::parse($binary);
    }

    private function fixturePath(string $fixture): string
    {
        return dirname(__DIR__).'/fixtures/'.$fixture;
    }

    /**
     * @param array<string, PropertyData> $properties
     */
    private function assertProperty(array $properties, string $id, int $typeId, int $flags, int|string $value): void
    {
        $property = $properties[strtolower($id)] ?? null;

        $this->assertInstanceOf(PropertyData::class, $property, sprintf('Missing property %s', $id));
        $this->assertSame($typeId, $property->propertyType->id, sprintf('Unexpected type for %s', $id));
        $this->assertSame($flags, $property->flags, sprintf('Unexpected flags for %s', $id));

        $actual = $property->valueOrSize;
        if ($actual instanceof \Brick\Math\BigInteger) {
            $actual = $actual->toBase(10);
        }

        $this->assertSame($value, $actual, sprintf('Unexpected value for %s', $id));
    }

    private function readUtf16Stream(CompoundFile $compound, int $childId, string $id): string
    {
        $raw = $this->readBinaryStream($compound, $childId, $id, '001f');

        if ($raw === '') {
            return '';
        }

        return rtrim(mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE'), "\0");
    }

    private function readBinaryStream(CompoundFile $compound, int $childId, string $id, string $type = '0102'): string
    {
        $entry = $compound->directory->get(
            sprintf('__substg1.0_%s%s', strtoupper($id), strtolower($type)),
            $childId,
            false,
        );

        $this->assertNotNull($entry, sprintf('Missing stream for %s%s', $id, $type));

        return $compound->readStreamToString($entry);
    }

    /**
     * @param RawProperty[] $properties
     * @return array<string, RawProperty>
     */
    private function rawPropertyMap(array $properties): array
    {
        $map = [];

        foreach ($properties as $property) {
            $map[strtoupper($property->id)] = $property;
        }

        return $map;
    }

    /**
     * @param array<string, RawProperty> $properties
     */
    private function assertRawString(array $properties, string $id, string $value): void
    {
        $property = $properties[$id] ?? null;

        $this->assertInstanceOf(RawProperty::class, $property, sprintf('Missing raw property %s', $id));
        $this->assertSame(0x001F, $property->typeId);
        $this->assertSame($value, $property->value);
        $this->assertSame(0x00000006, $property->flags);
    }

    /**
     * @param array<string, RawProperty> $properties
     */
    private function assertRawInt(array $properties, string $id, int $typeId, int $value): void
    {
        $property = $properties[$id] ?? null;

        $this->assertInstanceOf(RawProperty::class, $property, sprintf('Missing raw property %s', $id));
        $this->assertSame($typeId, $property->typeId);
        $this->assertSame($value, $property->value);
        $this->assertSame(0x00000006, $property->flags);
    }

    /**
     * @param array<string, RawProperty> $properties
     */
    private function assertRawBinary(array $properties, string $id, int $length, string $prefixHex): void
    {
        $property = $properties[$id] ?? null;

        $this->assertInstanceOf(RawProperty::class, $property, sprintf('Missing raw property %s', $id));
        $this->assertSame(0x0102, $property->typeId);
        $this->assertIsString($property->value);
        $this->assertSame($length, strlen($property->value));
        $this->assertStringStartsWith($prefixHex, bin2hex($property->value));
        $this->assertSame(0x00000006, $property->flags);
    }
}
