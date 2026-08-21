<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Support;

final class MimeType
{
    private const TYPES = [
        'bmp'  => 'image/bmp',
        'doc'  => 'application/msword',
        'docm' => 'application/vnd.ms-word.document.macroEnabled.12',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'dot'  => 'application/msword',
        'dotm' => 'application/vnd.ms-word.template.macroEnabled.12',
        'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
        'gif'  => 'image/gif',
        'jpeg' => 'image/jpeg',
        'jpg'  => 'image/jpeg',
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'txt'  => 'text/plain',
        'xls'  => 'application/vnd.ms-excel',
        'xlsb' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
        'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xlam' => 'application/vnd.ms-excel.addin.macroEnabled.12',
        'xltm' => 'application/vnd.ms-excel.template.macroEnabled.12',
        'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
    ];

    /**
     * Infer a MIME type from the final filename extension.
     */
    public static function fromFileName(?string $fileName): ?string
    {
        $extension = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));

        if ($extension === '') {
            return null;
        }

        return self::TYPES[$extension] ?? 'application/octet-stream';
    }
}
