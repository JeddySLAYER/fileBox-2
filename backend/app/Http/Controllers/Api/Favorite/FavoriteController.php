<?php

namespace App\Http\Controllers\Api\Favorite;

use App\Http\Controllers\Controller;
use App\Http\Resources\FavoriteResource;
use App\Models\Document;
use App\Models\Folder;
use App\Services\Favorite\FavoriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FavoriteController extends Controller
{
    public function __construct(
        private readonly FavoriteService $favoriteService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return FavoriteResource::collection(
            $this->favoriteService->listForUser($request->user())
        );
    }

    public function storeDocument(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $favorite = $this->favoriteService->add($request->user(), $document);

        return response()->json([
            'message' => 'Document ajouté aux favoris.',
            'favorite' => new FavoriteResource($favorite->load('favoritable')),
            'is_favorited' => true,
        ], 201);
    }

    public function destroyDocument(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $this->favoriteService->remove($request->user(), $document);

        return response()->json([
            'message' => 'Document retiré des favoris.',
            'is_favorited' => false,
        ]);
    }

    public function storeFolder(Request $request, Folder $folder): JsonResponse
    {
        $this->authorize('view', $folder);

        $favorite = $this->favoriteService->add($request->user(), $folder);

        return response()->json([
            'message' => 'Dossier ajouté aux favoris.',
            'favorite' => new FavoriteResource($favorite->load('favoritable')),
            'is_favorited' => true,
        ], 201);
    }

    public function destroyFolder(Request $request, Folder $folder): JsonResponse
    {
        $this->authorize('view', $folder);

        $this->favoriteService->remove($request->user(), $folder);

        return response()->json([
            'message' => 'Dossier retiré des favoris.',
            'is_favorited' => false,
        ]);
    }
}
