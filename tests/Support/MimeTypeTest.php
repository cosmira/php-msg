<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Tests\Support;

use Cosmira\OutlookMessage\Support\MimeType;
use PHPUnit\Framework\TestCase;

final class MimeTypeTest extends TestCase
{
    public function testInfersCommonAndOfficeMimeTypesCaseInsensitively(): void
    {
        $expected = [
            'image.PNG'   => 'image/png',
            'image.bmp'   => 'image/bmp',
            'image.gif'   => 'image/gif',
            'photo.jpeg'  => 'image/jpeg',
            'report.pdf'  => 'application/pdf',
            'notes.txt'   => 'text/plain',
            'report.doc'  => 'application/msword',
            'report.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'report.docm' => 'application/vnd.ms-word.document.macroEnabled.12',
            'report.dot'  => 'application/msword',
            'report.dotm' => 'application/vnd.ms-word.template.macroEnabled.12',
            'report.dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
            'report.xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'report.xls'  => 'application/vnd.ms-excel',
            'report.xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'report.xltm' => 'application/vnd.ms-excel.template.macroEnabled.12',
            'report.xlam' => 'application/vnd.ms-excel.addin.macroEnabled.12',
            'report.xlsb' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
            'report.xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
        ];

        foreach ($expected as $fileName => $mimeType) {
            $this->assertSame($mimeType, MimeType::fromFileName($fileName));
        }
    }

    public function testUnknownAndMissingExtensionsHaveSafeFallbacks(): void
    {
        $this->assertSame('application/octet-stream', MimeType::fromFileName('payload.unknown-extension'));
        $this->assertNull(MimeType::fromFileName('attachment'));
        $this->assertNull(MimeType::fromFileName(null));
    }
}
