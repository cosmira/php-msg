<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Support;

use Cosmira\OutlookMessage\Support\BinaryBuffer;
use Cosmira\OutlookMessage\Support\BinarySource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BinarySource::class)]
final class BinarySourceTest extends TestCase
{
    public function testBufferSourcesCopyAndHashEveryChunkAcrossTheOneMebibyteBoundary(): void
    {
        $contents = str_repeat('a', 1024 * 1024).'boundary';
        $source = BinarySource::fromBuffer(new BinaryBuffer($contents));
        $destination = fopen('php://temp', 'w+b');
        $this->assertIsResource($destination);

        $source->copyTo($destination);
        rewind($destination);

        $this->assertSame($contents, stream_get_contents($destination));
        $this->assertSame(hash('sha256', $contents), $source->hash());
        fclose($destination);
    }

    public function testFileSourcesRetainAStableSnapshotAndCanBeCopiedWithoutMaterializingThem(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'outlook-msg-source-');
        $this->assertIsString($path);

        try {
            file_put_contents($path, 'before');
            $source = BinarySource::fromBuffer(BinaryBuffer::fromPath($path));
            $replacement = $path.'.replacement';
            file_put_contents($replacement, 'after creation');
            rename($replacement, $path);
            $destination = fopen('php://temp', 'w+b');
            $this->assertIsResource($destination);

            $this->assertSame(6, $source->size());
            $source->copyTo($destination);
            rewind($destination);

            $this->assertSame('before', stream_get_contents($destination));
            $this->assertSame(hash('sha256', 'before'), $source->hash());
            $this->assertInstanceOf(BinaryBuffer::class, $source->buffer());
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
