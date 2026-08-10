<?php

namespace App\Http\Controllers\Api\Document;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\Ai\DocumentAiService;
use Illuminate\Http\JsonResponse;

class DocumentAiController extends Controller
{
    public function __construct(
        private readonly DocumentAiService $documentAi,
    ) {}

    public function summarize(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $document = $this->documentAi->summarize($document);

        return response()->json([
            'message' => 'Résumé généré.',
            'document' => new DocumentResource($document),
        ]);
    }

    public function analyze(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $document = $this->documentAi->analyze($document);

        return response()->json([
            'message' => 'Analyse générée.',
            'document' => new DocumentResource($document),
        ]);
    }

    public function ocr(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $document = $this->documentAi->ocr($document);

        return response()->json([
            'message' => 'OCR terminé.',
            'document' => new DocumentResource($document),
            'ocr_text' => $document->currentVersion?->ocr_text,
        ]);
    }
}
