<?php

namespace App\Http\Controllers\Api\Search;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\FolderResource;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('documents.view')
            || $user->hasPermission('folders.view')
            || $user->accesses()->active()->exists(),
            403
        );

        $filters = $request->only([
            'q',
            'status',
            'folder_id',
            'project_id',
            'department_id',
            'document_type_id',
            'tag',
            'tag_ids',
            'confidentiality',
            'author_id',
            'is_editable',
            'include_ocr',
        ]);

        if (is_string($request->input('tag_ids'))) {
            $filters['tag_ids'] = array_filter(explode(',', $request->input('tag_ids')));
        }

        $documents = $this->searchService->searchDocuments(
            $user,
            $filters,
            (int) $request->integer('per_page', 15),
        );

        $folders = $request->boolean('include_folders', true)
            ? $this->searchService->searchFolders($user, $request->only(['q', 'project_id', 'department_id']))
            : collect();

        return response()->json([
            'documents' => DocumentResource::collection($documents)->response()->getData(true),
            'folders' => FolderResource::collection($folders),
        ]);
    }
}
