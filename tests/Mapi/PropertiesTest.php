<?php

declare(strict_types=1);

namespace MsgViewer\Tests\Mapi;

use MsgViewer\Mapi\Properties;
use MsgViewer\Mapi\PropertySource;
use MsgViewer\Mapi\PropertyTypes;
use PHPUnit\Framework\TestCase;

final class PropertiesTest extends TestCase
{
    protected function setUp(): void
    {
        Properties::init();
    }

    public function testCodepageProperty(): void
    {
        $property = Properties::$codepageProperty;

        $this->assertSame('3FDE', $property->id);
        $this->assertSame('codepage', $property->name);
        $this->assertSame(PropertySource::Property, $property->source);
    }

    public function testRootPropertiesContainSubject(): void
    {
        $subject = array_filter(
            Properties::$rootProperties,
            static fn (\MsgViewer\Mapi\PropertyDefinition $prop) => $prop->name === 'subject'
        );

        $this->assertCount(1, $subject);
    }

    public function testCodepageMapHasUtf8(): void
    {
        $this->assertArrayHasKey(65001, Properties::$codepages);
        $this->assertSame('utf-8', Properties::$codepages[65001]);
    }

    public function testPropertyTypeLookup(): void
    {
        PropertyTypes::init();
        $type = PropertyTypes::get(PropertyTypes::$PtypString8->id);

        $this->assertSame(PropertyTypes::$PtypString8, $type);
    }
}
