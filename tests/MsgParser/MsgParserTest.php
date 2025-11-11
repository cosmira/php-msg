<?php

declare(strict_types=1);

namespace MsgViewer\Tests\MsgParser;

use MsgViewer\MessageParser;
use MsgViewer\Writer\CompoundBuilder;
use PHPUnit\Framework\TestCase;

final class MsgParserTest extends TestCase
{
    public function testParsesAnsiStringsUsingDeclaredCodepage(): void
    {
        $builder = new CompoundBuilder();
        $root = $builder->rootIndex();

        $header = str_repeat("\0", 8)
            .pack('V', 0) // nextRecipientId
            .pack('V', 0) // nextAttachmentId
            .pack('V', 0) // recipientCount
            .pack('V', 0) // attachmentCount
            .str_repeat("\0", 8);

        $ansiString = iconv('UTF-8', 'Windows-1251', 'Привет')."\0";

        $codepageEntry = pack('V', (0x3FDE << 16) | 0x0003)
            .pack('V', 0)
            .pack('V', 1251)
            .pack('V', 0);

        $subjectEntry = pack('V', (0x0037 << 16) | 0x001E)
            .pack('V', 0)
            .pack('V', strlen($ansiString))
            .pack('V', 0);

        $propertyStream = $header.$codepageEntry.$subjectEntry;

        $builder->addStream('__properties_version1.0', $propertyStream, $root);
        $builder->addStream('__substg1.0_0037001e', $ansiString, $root);

        $binary = $builder->build();
        $message = MessageParser::parse($binary);

        self::assertSame('Привет', $message->content->subject);
    }
}
