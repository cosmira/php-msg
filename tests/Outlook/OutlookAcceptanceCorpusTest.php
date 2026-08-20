<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Outlook;

use Cosmira\OutlookMessage\Message;
use PHPUnit\Framework\TestCase;

final class OutlookAcceptanceCorpusTest extends TestCase
{
    public function testVerifiesAnOutlookResavedManifest(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'php-msg-outlook-'.bin2hex(random_bytes(8));
        $resaved = $directory.DIRECTORY_SEPARATOR.'resaved';
        $this->assertTrue(mkdir($resaved, 0777, true));

        $data = "Outlook payload\0\xFF";
        $message = Message::make('Outlook acceptance')
            ->attachData($data, 'replacement.bin', 'application/octet-stream');
        $message->save($resaved.DIRECTORY_SEPARATOR.'case.msg');

        $manifest = [
            'schema' => 1,
            'cases'  => [[
                'source'           => 'generated',
                'file'             => 'case.msg',
                'subject'          => 'Outlook acceptance',
                'messageClass'     => 'IPM.Note',
                'attachmentName'   => 'replacement.bin',
                'attachmentSha256' => hash('sha256', $data),
            ]],
        ];

        try {
            OutlookAcceptanceCorpus::verifyResaved($directory, $manifest);
            $this->addToAssertionCount(1);
        } finally {
            @unlink($resaved.DIRECTORY_SEPARATOR.'case.msg');
            @rmdir($resaved);
            @rmdir($directory);
        }
    }
}
