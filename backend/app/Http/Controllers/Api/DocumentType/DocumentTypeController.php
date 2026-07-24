<?php

namespace App\Http\Controllers\Api\DocumentType;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentType\StoreDocumentTypeRequest;
use App\Http\Requests\DocumentType\UpdateDocumentTypeRequest;
use App\Http\Resources\DocumentTypeResource;
use App\Models\DocumentType;
use App\Services\DocumentType\DocumentTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentTypeController extends Controller
{
    public function __construct(
        private readonly DocumentTypeService $documentTypeService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DocumentType::class);

        return DocumentTypeResource::collection(
            $this->documentTypeService->list()
        );
    }

    public function store(StoreDocumentTypeRequest $request): JsonResponse
    {
        $this->authorize('create', DocumentType::class);

        $type = $this->documentTypeService->create($request->validated());

        return response()->json([
            'message' => 'Type de document créé.',
            'document_type' => new DocumentTypeResource($type),
        ], 201);
    }

    public function show(DocumentType $documentType): JsonResponse
    {
        $this->authorize('view', $documentType);

        $documentType->load('defaultWorkflow')->loadCount('documents');

        return response()->json([
            'document_type' => new DocumentTypeResource($documentType),
        ]);
    }

    public function update(UpdateDocumentTypeRequest $request, DocumentType $documentType): JsonResponse
    {
        $this->authorize('update', $documentType);

        $type = $this->documentTypeService->update($documentType, $request->validated());

        return response()->json([
            'message' => 'Type de document mis à jour.',
            'document_type' => new DocumentTypeResource($type),
        ]);
    }

    public function destroy(DocumentType $documentType): JsonResponse
    {
        $this->authorize('delete', $documentType);

        $this->documentTypeService->delete($documentType);

        return response()->json(['message' => 'Type de document supprimé.']);
    }
}
