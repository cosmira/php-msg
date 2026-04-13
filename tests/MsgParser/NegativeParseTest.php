<?php

declare(strict_types=1);

namespace MsgViewer\Tests\MsgParser;

use MsgViewer\Exception\CorruptedFileException;
use MsgViewer\Exception\ParseException;
use MsgViewer\MessageParser;
use PHPUnit\Framework\TestCase;

final class NegativeParseTest extends TestCase
{
    public function testEmptyStringThrowsCorruptedFileException(): void
    {
        $this->expectException(CorruptedFileException::class);
        MessageParser::parse('');
    }

    public function testRandomBytesThrowsCorruptedFileException(): void
    {
        $this->expectException(CorruptedFileException::class);
        MessageParser::parse(random_bytes(512));
    }

    public function testTruncatedHeaderThrowsCorruptedFileException(): void
    {
        $this->expectException(CorruptedFileException::class);
        // CFB magic + partial header
        MessageParser::parse("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 40));
    }

    public function testWrongMagicThrowsCorruptedFileException(): void
    {
        $this->expectException(CorruptedFileException::class);
        // Valid-length header but wrong magic bytes
        MessageParser::parse(str_repeat("\xAB", 512));
    }

    public function testParseExceptionIsSubclassOfRuntimeException(): void
    {
        try {
            MessageParser::parse('not a msg file');
        } catch (\RuntimeException $e) {
            // Must be catchable as RuntimeException for backward compat
            $this->assertInstanceOf(CorruptedFileException::class, $e);
            return;
        }

        $this->fail('Expected RuntimeException');
    }

    public function testCorruptedFileExceptionExtendsParseException(): void
    {
        try {
            MessageParser::parse('nope');
        } catch (ParseException $e) {
            $this->assertInstanceOf(CorruptedFileException::class, $e);
            return;
        }

        $this->fail('Expected ParseException');
    }
}
