<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\Setting\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class BackupService
{
    private const DISK = 'local';

    private const TABLES = [
        'system_settings',
        'roles',
        'permissions',
        'permission_role',
        'role_user',
        'departments',
        'projects',
        'project_user',
        'workflows',
        'workflow_steps',
        'document_types',
        'folders',
        'documents',
        'versions',
        'tags',
        'document_tag',
        'comments',
        'validations',
        'accesses',
        'users',
    ];

    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly SettingService $settings,
    ) {}

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Backup>
     */
    public function list()
    {
        return Backup::query()->with('creator')->latest()->get();
    }

    public function create(User $actor, ?string $notes = null): Backup
    {
        $timestamp = now()->format('Ymd_His');
        $name = "backup_{$timestamp}";
        $relativeDir = "backups/{$name}";
        $zipRelative = "{$relativeDir}/{$name}.zip";

        Storage::disk(self::DISK)->makeDirectory($relativeDir);

        $payload = [
            'created_at' => now()->toIso8601String(),
            'created_by' => $actor->id,
            'includes_files' => true,
            'tables' => [],
        ];

        foreach (self::TABLES as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            $payload['tables'][$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        $jsonPath = Storage::disk(self::DISK)->path("{$relativeDir}/data.json");
        file_put_contents($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $zipPath = Storage::disk(self::DISK)->path($zipRelative);
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ValidationException::withMessages([
                'backup' => ['Impossible de créer l\'archive de sauvegarde.'],
            ]);
        }

        $zip->addFile($jsonPath, 'data.json');
        $this->addDocumentsToZip($zip);
        $zip->close();

        @unlink($jsonPath);

        $backup = Backup::query()->create([
            'name' => $name,
            'path' => $zipRelative,
            'size' => Storage::disk(self::DISK)->size($zipRelative),
            'status' => 'completed',
            'notes' => $notes,
            'created_by' => $actor->id,
        ]);

        $this->activityLog->log(
            action: 'backup.created',
            user: $actor,
            subject: $backup,
            description: "Sauvegarde créée : {$name}",
        );

        $this->applyRetention();

        return $backup->load('creator');
    }

    public function restore(Backup $backup, User $actor): Backup
    {
        if (! Storage::disk(self::DISK)->exists($backup->path)) {
            throw ValidationException::withMessages([
                'backup' => ['Fichier de sauvegarde introuvable.'],
            ]);
        }

        $zipPath = Storage::disk(self::DISK)->path($backup->path);
        $extractDir = Storage::disk(self::DISK)->path('backups/_restore_'.uniqid());
        mkdir($extractDir, 0755, true);

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw ValidationException::withMessages([
                'backup' => ['Archive de sauvegarde illisible.'],
            ]);
        }
        $zip->extractTo($extractDir);
        $zip->close();

        $jsonFile = $extractDir.DIRECTORY_SEPARATOR.'data.json';
        if (! is_file($jsonFile)) {
            throw ValidationException::withMessages([
                'backup' => ['Le fichier data.json est manquant dans la sauvegarde.'],
            ]);
        }

        $payload = json_decode(file_get_contents($jsonFile), true);
        if (! is_array($payload) || ! isset($payload['tables'])) {
            throw ValidationException::withMessages([
                'backup' => ['Format de sauvegarde invalide.'],
            ]);
        }

        // ponytail: full table replace restore; upgrade: selective restore
        DB::transaction(function () use ($payload) {
            Schema::disableForeignKeyConstraints();

            try {
                $tables = array_reverse(self::TABLES);

                foreach ($tables as $table) {
                    if (DB::getSchemaBuilder()->hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }

                foreach (self::TABLES as $table) {
                    if (! isset($payload['tables'][$table]) || ! DB::getSchemaBuilder()->hasTable($table)) {
                        continue;
                    }

                    $rows = $payload['tables'][$table];
                    foreach (array_chunk($rows, 100) as $chunk) {
                        if ($chunk !== []) {
                            $normalized = array_map(function (array $row) {
                                foreach ($row as $key => $value) {
                                    if (is_array($value)) {
                                        $row[$key] = json_encode($value);
                                    }
                                }

                                return $row;
                            }, $chunk);

                            DB::table($table)->insert($normalized);
                        }
                    }
                }
            } finally {
                Schema::enableForeignKeyConstraints();
            }
        });

        $this->restoreDocumentsFromExtract($extractDir);
        $this->cleanupDir($extractDir);

        $backup->restored_at = now();
        $backup->save();

        $this->activityLog->log(
            action: 'backup.restored',
            user: $actor,
            subject: $backup,
            description: "Sauvegarde restaurée : {$backup->name}",
        );

        return $backup->load('creator');
    }

    public function delete(Backup $backup): void
    {
        if (Storage::disk(self::DISK)->exists($backup->path)) {
            Storage::disk(self::DISK)->delete($backup->path);
        }

        $dir = dirname($backup->path);
        if (Storage::disk(self::DISK)->exists($dir)) {
            Storage::disk(self::DISK)->deleteDirectory($dir);
        }

        $backup->delete();
    }

    public function absolutePath(Backup $backup): string
    {
        return Storage::disk(self::DISK)->path($backup->path);
    }

    /**
     * Supprime les sauvegardes plus anciennes que backup.retention_days.
     */
    public function applyRetention(): int
    {
        $days = (int) $this->settings->get('backup.retention_days', 30);
        if ($days <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        Backup::query()
            ->where('created_at', '<', $cutoff)
            ->get()
            ->each(function (Backup $backup) use (&$deleted) {
                $this->delete($backup);
                $deleted++;
            });

        return $deleted;
    }

    private function addDocumentsToZip(ZipArchive $zip): void
    {
        $docsRoot = Storage::disk(self::DISK)->path('documents');
        if (! is_dir($docsRoot)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($docsRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $absolute = $file->getPathname();
            $relative = 'files/documents/'.ltrim(
                str_replace('\\', '/', substr($absolute, strlen($docsRoot))),
                '/'
            );
            $zip->addFile($absolute, $relative);
        }
    }

    private function restoreDocumentsFromExtract(string $extractDir): void
    {
        $filesDir = $extractDir.DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR.'documents';
        if (! is_dir($filesDir)) {
            return;
        }

        $targetRoot = Storage::disk(self::DISK)->path('documents');
        if (is_dir($targetRoot)) {
            Storage::disk(self::DISK)->deleteDirectory('documents');
        }
        Storage::disk(self::DISK)->makeDirectory('documents');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($filesDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($filesDir))), '/');
            $dest = $targetRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if ($item->isDir()) {
                if (! is_dir($dest)) {
                    mkdir($dest, 0755, true);
                }
            } else {
                $parent = dirname($dest);
                if (! is_dir($parent)) {
                    mkdir($parent, 0755, true);
                }
                copy($item->getPathname(), $dest);
            }
        }
    }

    private function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->cleanupDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
