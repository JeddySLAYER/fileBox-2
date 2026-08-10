<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Édition en ligne FileBox = formats texte uniquement.
 * Office (docx/xlsx/pptx) → non éditable tant qu'un moteur (OnlyOffice/M365) n'est pas branché.
 */
final class DocumentEditability
{
    /** @var list<string> */
    public const ONLINE_EXTENSIONS = [
        'txt',
        'md',
        'markdown',
        'csv',
        'tsv',
        'json',
        'xml',
        'html',
        'htm',
        'css',
        'js',
        'log',
    ];

    public static function fromFileName(?string $fileName): bool
    {
        $ext = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));

        return self::fromExtension($ext);
    }

    public static function fromUploadedFile(UploadedFile $file): bool
    {
        return self::fromFileName($file->getClientOriginalName());
    }

    public static function fromExtension(?string $extension): bool
    {
        $ext = strtolower(ltrim((string) $extension, '.'));

        return $ext !== '' && in_array($ext, self::ONLINE_EXTENSIONS, true);
    }
}
