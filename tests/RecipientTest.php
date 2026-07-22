<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests;

use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Recipient;
use PHPUnit\Framework\TestCase;

final class RecipientTest extends TestCase
{
    public function testRecipientMethodAliasesProxyProperties(): void
    {
        $raw = new RawProperty('1234', 0x0003, 42, 0);
        $recipient = new Recipient('Jane', 'jane@example.com', 2, [$raw]);

        $this->assertSame('Jane', $recipient->name());
        $this->assertSame('jane@example.com', $recipient->email());
        $this->assertSame(2, $recipient->type());
        $this->assertSame([$raw], $recipient->rawProperties());
        $this->assertSame([$raw], $recipient->getRawProperties());
    }
}
