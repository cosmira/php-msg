<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests;

use Cosmira\OutlookMessage\RawProperty;
use PHPUnit\Framework\TestCase;

final class RawPropertyTest extends TestCase
{
    public function testTagReturnsEightCharHex(): void
    {
        $prop = new RawProperty('0037', 0x001F, 'Hello');

        $this->assertSame('0037001f', $prop->tag());
    }

    public function testTagPadsTypeIdWithLeadingZeros(): void
    {
        $prop = new RawProperty('0040', 0x0003, 42);

        $this->assertSame('00400003', $prop->tag());
    }

    public function testTagHandlesLargeTypeId(): void
    {
        $prop = new RawProperty('1234', 0x0102, 'binary');

        $this->assertSame('12340102', $prop->tag());
    }
}
