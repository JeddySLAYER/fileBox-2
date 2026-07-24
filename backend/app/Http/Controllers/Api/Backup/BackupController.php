<?php

namespace App\Http\Controllers\Api\Backup;

use App\Http\Controllers\Controller;
use App\Http\Resources\BackupResource;
use App\Models\Backup;
use App\Services\Backup\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function __construct(
        private readonly BackupService $backupService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);

        return BackupResource::collection($this->backupService->list());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $backup = $this->backupService->create($request->user(), $data['notes'] ?? null);

        return response()->json([
            'message' => 'Sauvegarde créée.',
            'backup' => new BackupResource($backup),
        ], 201);
    }

    public function download(Request $request, Backup $backup): BinaryFileResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);

        return response()->download(
            $this->backupService->absolutePath($backup),
            $backup->name.'.zip',
        );
    }

    public function restore(Request $request, Backup $backup): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);

        $backup = $this->backupService->restore($backup, $request->user());

        return response()->json([
            'message' => 'Sauvegarde restaurée. Reconnectez-vous si nécessaire.',
            'backup' => new BackupResource($backup),
        ]);
    }

    public function destroy(Request $request, Backup $backup): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);

        $this->backupService->delete($backup);

        return response()->json([
            'message' => 'Sauvegarde supprimée.',
        ]);
    }
}
