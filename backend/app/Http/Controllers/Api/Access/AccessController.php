<?php

namespace App\Http\Controllers\Api\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\StoreAccessRequest;
use App\Http\Requests\Access\UpdateAccessRequest;
use App\Http\Resources\AccessResource;
use App\Models\Access;
use App\Models\Document;
use App\Models\Folder;
use App\Services\Access\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccessController extends Controller
{
    public function __construct(
        private readonly AccessService $accessService,
    ) {}

    public function mine(Request $request): JsonResponse
    {
        $activeOnly = ! $request->boolean('include_expired');
        $user = $request->user();

        $received = AccessResource::collection(
            $this->accessService->listForUser($user, activeOnly: $activeOnly)
        )->resolve();
        $granted = AccessResource::collection(
            $this->accessService->listGrantedByUser($user, activeOnly: $activeOnly)
        )->resolve();

        return response()->json([
            // BC : data = reçus (comportement historique)
            'data' => $received,
            'received' => $received,
            'granted' => $granted,
        ]);
    }

    public function forDocument(Document $document): AnonymousResourceCollection
    {
        $this->authorize('share', $document);

        return AccessResource::collection(
            $this->accessService->listForResource($document)
        );
    }

    public function forFolder(Folder $folder): AnonymousResourceCollection
    {
        $this->authorize('share', $folder);

        return AccessResource::collection(
            $this->accessService->listForResource($folder)
        );
    }

    public function storeForDocument(StoreAccessRequest $request, Document $document): JsonResponse
    {
        $this->authorize('share', $document);

        $accesses = $this->accessService->grantMany(
            $request->user(),
            $document,
            $request->validated(),
        );

        $count = count($accesses);

        return response()->json([
            'message' => $count > 1
                ? "Accès accordé à {$count} utilisateurs."
                : 'Accès accordé sur le document.',
            'access' => new AccessResource($accesses[0]),
            'accesses' => AccessResource::collection(collect($accesses)),
        ], 201);
    }

    public function storeForFolder(StoreAccessRequest $request, Folder $folder): JsonResponse
    {
        $this->authorize('share', $folder);

        $accesses = $this->accessService->grantMany(
            $request->user(),
            $folder,
            $request->validated(),
        );

        $count = count($accesses);

        return response()->json([
            'message' => $count > 1
                ? "Accès accordé à {$count} utilisateurs (héritage dossiers/documents)."
                : 'Accès accordé sur le dossier (hérité par sous-dossiers et documents).',
            'access' => new AccessResource($accesses[0]),
            'accesses' => AccessResource::collection(collect($accesses)),
        ], 201);
    }

    public function update(UpdateAccessRequest $request, Access $access): JsonResponse
    {
        $this->authorize('update', $access);

        $access = $this->accessService->update($access, $request->validated());

        return response()->json([
            'message' => 'Accès mis à jour.',
            'access' => new AccessResource($access),
        ]);
    }

    public function destroy(Access $access): JsonResponse
    {
        $this->authorize('delete', $access);

        $this->accessService->revoke($access);

        return response()->json([
            'message' => 'Accès révoqué.',
        ]);
    }
}
