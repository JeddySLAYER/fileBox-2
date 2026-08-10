<?php

namespace App\Http\Controllers\Api\Folder;

use App\Http\Controllers\Controller;
use App\Http\Requests\Folder\MoveFolderRequest;
use App\Http\Requests\Folder\StoreFolderRequest;
use App\Http\Requests\Folder\UpdateFolderRequest;
use App\Http\Resources\FolderResource;
use App\Models\Folder;
use App\Services\Folder\FolderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FolderController extends Controller
{
    public function __construct(
        private readonly FolderService $folderService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Folder::class);

        $folders = $this->folderService->list(
            $request->user(),
            $request->only([
                'parent_id',
                'project_id',
                'department_id',
                'trashed',
            ])
        );

        return FolderResource::collection($folders);
    }

    public function tree(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Folder::class);

        $folders = $this->folderService->tree(
            actor: $request->user(),
            projectId: $request->integer('project_id') ?: null,
            departmentId: $request->integer('department_id') ?: null,
        );

        return FolderResource::collection($folders);
    }

    public function store(StoreFolderRequest $request): JsonResponse
    {
        $this->authorize('create', Folder::class);

        $folder = $this->folderService->create($request->user(), $request->validated());

        return response()->json([
            'message' => 'Dossier créé.',
            'folder' => new FolderResource($folder),
        ], 201);
    }

    public function show(Folder $folder): JsonResponse
    {
        $this->authorize('view', $folder);

        $folder->load(['creator', 'project', 'department', 'children', 'tags'])
            ->loadCount(['children', 'documents'])
            ->loadExists([
                'favorites as is_favorited' => fn ($q) => $q->where('user_id', request()->user()->id),
            ]);

        return response()->json([
            'folder' => new FolderResource($folder),
        ]);
    }

    public function update(UpdateFolderRequest $request, Folder $folder): JsonResponse
    {
        $this->authorize('update', $folder);

        $folder = $this->folderService->update($folder, $request->validated());

        return response()->json([
            'message' => 'Dossier mis à jour.',
            'folder' => new FolderResource($folder),
        ]);
    }

    public function move(MoveFolderRequest $request, Folder $folder): JsonResponse
    {
        $this->authorize('update', $folder);

        $folder = $this->folderService->move($folder, $request->validated('parent_id'));

        return response()->json([
            'message' => 'Dossier déplacé.',
            'folder' => new FolderResource($folder),
        ]);
    }

    public function destroy(Folder $folder): JsonResponse
    {
        $this->authorize('delete', $folder);

        $this->folderService->delete($folder);

        return response()->json([
            'message' => 'Dossier placé dans la corbeille.',
        ]);
    }

    public function restore(int $id): JsonResponse
    {
        $folder = Folder::onlyTrashed()->findOrFail($id);

        $this->authorize('restore', $folder);

        $folder = $this->folderService->restore($folder);

        return response()->json([
            'message' => 'Dossier restauré.',
            'folder' => new FolderResource($folder),
        ]);
    }
}
