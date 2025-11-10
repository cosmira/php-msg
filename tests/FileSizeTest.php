<?php

declare(strict_types=1);

use MsgViewer\Utils\FileSize;
use PHPUnit\Framework\TestCase;

final class FileSizeTest extends TestCase
{
    public function testBytesFormatting(): void
    {
        self::assertSame('500 bytes', FileSize::bytesWithUnits(500));
        self::assertSame('1.0 KB', FileSize::bytesWithUnits(1000));
        self::assertSame('1.5 KB', FileSize::bytesWithUnits(1500));
    }
}

