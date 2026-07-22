<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Mapi;

use Cosmira\OutlookMessage\Mapi\Properties;
use Cosmira\OutlookMessage\Mapi\PropertyDefinition;
use Cosmira\OutlookMessage\Mapi\PropertySource;
use Cosmira\OutlookMessage\Mapi\PropertyTypes;
use PHPUnit\Framework\TestCase;

final class MapiDefinitionsSnapshotTest extends TestCase
{
    public function testUnknownNamedPropertyTypeIsRejected(): void
    {
        PropertyTypes::init();
        $method = new \ReflectionMethod(PropertyTypes::class, 'registerNamedType');

        $this->expectException(\LogicException::class);
        $method->invoke(null, 'Unknown', PropertyTypes::$PtypInteger32);
    }

    public function testDefinitionsAreStable(): void
    {
        self::resetArray(PropertyTypes::class, 'MAP');
        self::resetArray(Properties::class, 'rootProperties');
        self::resetArray(Properties::class, 'attachmentProperties');
        self::resetArray(Properties::class, 'recipientProperties');
        self::resetArray(Properties::class, 'codepages');

        PropertyTypes::init();
        Properties::init();

        $types = [];
        foreach (PropertyTypes::$MAP as $id => $type) {
            $types[] = [$id, $type->name, $type->size, $type->multi];
        }

        $this->assertSnapshot($types, 27, 'e85fba07b0269774968c37c92e9e9ac2b48544fbd57a090af65a58f3f7c993a2');
        $this->assertSnapshot(Properties::$codepages, 37, '77b3fe9102bb880015eeda5008b651a1fc77d6b03681fa3e41a1e1620312c2c5');
        $this->assertSnapshot(self::normalize(Properties::$rootProperties), 42, '725640158693cef796a977b4cbc4a2cc75d50c057d51389318d1aa36f32693d7');
        $this->assertSnapshot(self::normalize(Properties::$attachmentProperties), 20, '6c3c2c1f7d4a7d023a8da6ae0828fb034581e331c22a5d553c6e92ed489ac77a');
        $this->assertSnapshot(self::normalize(Properties::$recipientProperties), 13, 'd11021d5da3ccf02c2e5a953482ba6837cb7377463bebadcf008d6d56c4aa379');
        $this->assertSnapshot(self::normalize([Properties::$codepageProperty]), 1, '9161e01039f50e125a24d029ecf4ff6c0d7641f7730c3aac0ce9ab70613db507');

        $rootDefinitions = Properties::$rootProperties;
        Properties::init();
        $this->assertSame($rootDefinitions, Properties::$rootProperties);

        $defaultFlags = new PropertyDefinition('FFFF', 'default', [], PropertySource::Property);
        $this->assertSame(0, $defaultFlags->flags);

        self::resetArray(PropertyTypes::class, 'MAP');
        $this->assertSame('PtypInteger32', PropertyTypes::get(0x0003)?->name);

        // Keep the process-wide definition tables internally consistent for tests
        // that run after this snapshot in randomized order.
        self::resetArray(Properties::class, 'rootProperties');
        self::resetArray(Properties::class, 'attachmentProperties');
        self::resetArray(Properties::class, 'recipientProperties');
        self::resetArray(Properties::class, 'codepages');
        Properties::init();
    }

    /** @param array<mixed> $value */
    private function assertSnapshot(array $value, int $count, string $hash): void
    {
        $this->assertCount($count, $value);
        $this->assertSame($hash, hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)));
    }

    /** @param class-string $class */
    private static function resetArray(string $class, string $property): void
    {
        (new \ReflectionProperty($class, $property))->setValue(null, []);
    }

    /**
     * @param PropertyDefinition[] $definitions
     *
     * @return list<array{id:string,name:string,types:list<int>,source:string,flags:int}>
     */
    private static function normalize(array $definitions): array
    {
        return array_values(array_map(static fn (PropertyDefinition $property): array => [
            'id'     => $property->id,
            'name'   => $property->name,
            'types'  => array_values(array_map(static fn ($type): int => $type->id, $property->types)),
            'source' => $property->source->value,
            'flags'  => $property->flags,
        ], $definitions));
    }
}
