<?php

namespace App\Http\Controllers\Api\Trash;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Folder;
use App\Services\Trash\TrashService;
use Illuminate\Http\JsonResponse;

class TrashController extends Controller
{
    public function __construct(
        private readonly TrashService $trashService,
    ) {}

    public function empty(): JsonResponse
    {
        $this->authorize('viewAny', Document::class);

        $counts = $this->trashService->emptyFor(request()->user());

        return response()->json([
            'message' => 'Corbeille vidée.',
            'deleted' => $counts,
        ]);
    }
}
