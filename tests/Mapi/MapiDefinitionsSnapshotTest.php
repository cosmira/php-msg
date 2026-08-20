<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Mapi;

use Cosmira\OutlookMessage\Mapi\Properties;
use Cosmira\OutlookMessage\Mapi\PropertyDefinition;
use Cosmira\OutlookMessage\Mapi\PropertySource;
use Cosmira\OutlookMessage\Mapi\PropertyType;
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
        $this->resetArray(PropertyTypes::class, 'MAP');
        $this->resetArray(Properties::class, 'rootProperties');
        $this->resetArray(Properties::class, 'attachmentProperties');
        $this->resetArray(Properties::class, 'recipientProperties');
        $this->resetArray(Properties::class, 'codepages');

        PropertyTypes::init();
        Properties::init();

        $types = [];
        foreach (PropertyTypes::$MAP as $id => $type) {
            $types[] = [$id, $type->name, $type->size, $type->multi];
        }

        $this->assertSnapshot($types, 27, '652062380721e930182fb76da5dcd29e4e551e982d7b5ee62fd58cbd787921ef');
        $this->assertSnapshot(Properties::$codepages, 38, '1297d5b25e57743900b4f328c2d9a852cdaedd95ae34b8582eb16fde6ad3c7b0');
        $this->assertSnapshot($this->normalize(Properties::$rootProperties), 53, '84a550c8ab4b51ad980092b21e58470c0a2ee8b8f54477c16fd59d861c94f45b');
        $this->assertSnapshot($this->normalize(Properties::$attachmentProperties), 20, '6c3c2c1f7d4a7d023a8da6ae0828fb034581e331c22a5d553c6e92ed489ac77a');
        $this->assertSnapshot($this->normalize(Properties::$recipientProperties), 13, 'd11021d5da3ccf02c2e5a953482ba6837cb7377463bebadcf008d6d56c4aa379');
        $this->assertSnapshot($this->normalize([Properties::$codepageProperty]), 1, '9161e01039f50e125a24d029ecf4ff6c0d7641f7730c3aac0ce9ab70613db507');

        $rootDefinitions = Properties::$rootProperties;
        Properties::init();
        $this->assertSame($rootDefinitions, Properties::$rootProperties);

        $defaultFlags = new PropertyDefinition('FFFF', 'default', [], PropertySource::Property);
        $this->assertSame(0, $defaultFlags->flags);

        $this->resetArray(PropertyTypes::class, 'MAP');
        $this->assertSame('PtypInteger32', PropertyTypes::get(0x0003)?->name);

        // Keep the process-wide definition tables internally consistent for tests
        // that run after this snapshot in randomized order.
        $this->resetArray(Properties::class, 'rootProperties');
        $this->resetArray(Properties::class, 'attachmentProperties');
        $this->resetArray(Properties::class, 'recipientProperties');
        $this->resetArray(Properties::class, 'codepages');
        Properties::init();
    }

    /**
     * @param array<mixed> $value
     *
     * @throws \JsonException
     */
    private function assertSnapshot(array $value, int $count, string $hash): void
    {
        $this->assertCount($count, $value);
        $this->assertSame($hash, hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)));
    }

    /**
     * @param class-string $class
     *
     * @throws \ReflectionException
     */
    private function resetArray(string $class, string $property): void
    {
        (new \ReflectionProperty($class, $property))->setValue(null, []);
    }

    /**
     * @param PropertyDefinition[] $definitions
     *
     * @return list<array{id:string,name:string,types:list<int>,source:string,flags:int}>
     */
    private function normalize(array $definitions): array
    {
        return array_values(array_map(static fn (PropertyDefinition $property): array => [
            'id'     => $property->id,
            'name'   => $property->name,
            'types'  => array_values(array_map(static fn (PropertyType $type): int => $type->id, $property->types)),
            'source' => $property->source->value,
            'flags'  => $property->flags,
        ], $definitions));
    }
}
