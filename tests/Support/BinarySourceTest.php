<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Support;

use Cosmira\OutlookMessage\Support\BinarySource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BinarySource::class)]
final class BinarySourceTest extends TestCase
{
    public function testFileSourcesAreLazyAndCanBeCopiedWithoutMaterializingThem(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'outlook-msg-source-');
        $this->assertIsString($path);

        try {
            file_put_contents($path, 'before');
            $source = BinarySource::fromPath($path);
            file_put_contents($path, 'after creation');
            $destination = fopen('php://temp', 'w+b');
            $this->assertIsResource($destination);

            $this->assertSame(14, $source->size());
            $source->copyTo($destination);
            rewind($destination);

            $this->assertSame('after creation', stream_get_contents($destination));
            $this->assertSame(hash('sha256', 'after creation'), $source->hash());
            fclose($destination);
        } finally {
            @unlink($path);
        }
    }

    public function testWriterMustProduceItsDeclaredByteLength(): void
    {
        $source = BinarySource::fromWriter(2, static function ($destination): void {
            fwrite($destination, 'one');
        });
        $destination = fopen('php://temp', 'w+b');
        $this->assertIsResource($destination);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('declared byte length');
        $source->copyTo($destination);
    }
}
