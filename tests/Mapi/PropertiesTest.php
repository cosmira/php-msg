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

        self::assertSame('3FDE', $property->id);
        self::assertSame('codepage', $property->name);
        self::assertSame(PropertySource::Property, $property->source);
    }

    public function testRootPropertiesContainSubject(): void
    {
        $subject = array_filter(
            Properties::$rootProperties,
            static fn ($prop) => $prop->name === 'subject'
        );

        self::assertCount(1, $subject);
    }

    public function testCodepageMapHasUtf8(): void
    {
        self::assertArrayHasKey(65001, Properties::$codepages);
        self::assertSame('utf-8', Properties::$codepages[65001]);
    }

    public function testPropertyTypeLookup(): void
    {
        PropertyTypes::init();
        $type = PropertyTypes::get(PropertyTypes::$PtypString8->id);

        self::assertSame(PropertyTypes::$PtypString8, $type);
    }
}
