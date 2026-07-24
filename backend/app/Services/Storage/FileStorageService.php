<?php

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileStorageService
{
    private const DISK = 'local';

    /**
     * @return array{file_path: string, file_name: string, mime_type: string|null, extension: string|null, size: int, checksum: string}
     */
    public function storeDocumentFile(UploadedFile $file, int $documentId, int $versionNumber): array
    {
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'fichier';
        $storedName = $safeName.($extension ? '.'.$extension : '');

        $directory = "documents/{$documentId}/v{$versionNumber}";
        $path = $file->storeAs($directory, $storedName, self::DISK);

        return [
            'file_path' => $path,
            'file_name' => $originalName,
            'mime_type' => $file->getClientMimeType(),
            'extension' => $extension,
            'size' => $file->getSize() ?: 0,
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    /**
     * @return array{file_path: string, file_name: string, mime_type: string|null, extension: string|null, size: int, checksum: string}
     */
    public function storeDocumentContent(
        string $content,
        int $documentId,
        int $versionNumber,
        string $fileName = 'content.txt',
        string $mimeType = 'text/plain',
    ): array {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION) ?: 'txt';
        $safeName = Str::slug(pathinfo($fileName, PATHINFO_FILENAME)) ?: 'content';
        $storedName = $safeName.'.'.$extension;
        $directory = "documents/{$documentId}/v{$versionNumber}";
        $path = "{$directory}/{$storedName}";

        Storage::disk(self::DISK)->put($path, $content);

        return [
            'file_path' => $path,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size' => strlen($content),
            'checksum' => hash('sha256', $content),
        ];
    }

    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    public function get(string $path): ?string
    {
        return Storage::disk(self::DISK)->get($path);
    }

    public function absolutePath(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    public function isPreviewable(?string $mimeType): bool
    {
        if (! $mimeType) {
            return false;
        }

        return str_starts_with($mimeType, 'image/')
            || $mimeType === 'application/pdf'
            || str_starts_with($mimeType, 'text/');
    }
}
